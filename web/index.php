<?php
require_once __DIR__ . '/config.php';

// Load settings for autobuyer status
$settings = [];
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
}
$autobuyerEnabled = $settings['autobuyer']['enabled'] ?? true;

// Load current permit to compare
$currentPermit = null;
if (file_exists($permitFile)) {
    $currentPermit = json_decode(file_get_contents($permitFile), true);
}

// Check if specific permit requested
$requestedPermit = isset($_GET['permit']) ? $_GET['permit'] : null;
$permit = null;
$isHistorical = false;

if ($requestedPermit && file_exists($historyFile)) {
    // Search history for specific permit
    $history = json_decode(file_get_contents($historyFile), true) ?: [];
    foreach ($history as $p) {
        if (isset($p['permitNumber']) && $p['permitNumber'] === $requestedPermit) {
            $permit = $p;
            // Only mark as historical if it's NOT the current permit
            if ($currentPermit && $currentPermit['permitNumber'] !== $requestedPermit) {
                $isHistorical = true;
            }
            break;
        }
    }
}

// No specific permit requested: show the permit that should display right now -- the
// active one, or the next (already-bought) one from 4 PM on the last valid day. Mirrors
// the Python display logic so the web view agrees with the e-ink even if permit.json is
// briefly stale between cron runs.
if (!$permit && $currentPermit) {
    $permit = $currentPermit;
    if (file_exists($historyFile)) {
        $history = json_decode(file_get_contents($historyFile), true) ?: [];
        $plate = strtoupper($currentPermit['plateNumber'] ?? '');
        $forPlate = array_values(array_filter($history, fn($p) => strtoupper($p['plateNumber'] ?? '') === $plate));
        $selected = $plate ? selectDisplayPermit($forPlate, new DateTime()) : null;
        if ($selected) $permit = $selected;
    }
}

// Load car nicknames
$cars = [];
if (file_exists($carsFile)) {
    $cars = json_decode(file_get_contents($carsFile), true) ?: [];
}

// Find nickname for plate
$nickname = null;
if ($permit && isset($permit['plateNumber'])) {
    foreach ($cars as $car) {
        if (strtoupper($car['plate']) === strtoupper($permit['plateNumber'])) {
            $nickname = $car['name'];
            break;
        }
    }
}

// Hour at which, on a permit's last valid day, the display flips to the next permit.
// Keep in sync with DISPLAY_FLIP_HOUR in parking_pass_buyer.py.
const DISPLAY_FLIP_HOUR = 16;

function parsePermitDt($value) {
    $s = trim($value ?? '');
    foreach (['M d, Y: H:i', 'M j, Y: H:i', 'M d, Y \a\t g:i A', 'M j, Y \a\t g:i A', 'M d, Y', 'M j, Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt !== false) return $dt;
    }
    return null;
}

// Pick which of a plate's permits to display at $now. Normally the currently-valid one;
// on its last valid day from DISPLAY_FLIP_HOUR onward, the next (already-bought) permit.
function selectDisplayPermit($permits, $now) {
    $parsed = [];
    foreach ($permits as $p) {
        $vf = parsePermitDt($p['validFrom'] ?? '');
        $vt = parsePermitDt($p['validTo'] ?? '');
        if ($vf && $vt) $parsed[] = ['vf' => $vf, 'vt' => $vt, 'p' => $p];
    }
    if (!$parsed) return null;
    $nowDate = $now->format('Y-m-d');

    $current = array_values(array_filter($parsed, fn($t) =>
        $t['vf']->format('Y-m-d') <= $nowDate && $nowDate <= $t['vt']->format('Y-m-d')));
    $future = array_values(array_filter($parsed, fn($t) => $t['vf']->format('Y-m-d') > $nowDate));
    usort($future, fn($a, $b) => $a['vf'] <=> $b['vf']);

    if ($current) {
        usort($current, fn($a, $b) => $a['vf'] <=> $b['vf']);
        $cur = end($current);
        if ($future) {
            $flipAt = (clone $cur['vt']);
            $flipAt->setTime(DISPLAY_FLIP_HOUR, 0, 0);
            if ($now >= $flipAt) return $future[0]['p'];
        }
        return $cur['p'];
    }
    if ($future) return $future[0]['p'];
    usort($parsed, fn($a, $b) => $a['vf'] <=> $b['vf']);
    return end($parsed)['p'];
}

// Parse dates and format with AM/PM
function formatDateTime($dateStr) {
    if (preg_match('/^(.+):\s*(\d{1,2}):(\d{2})$/', $dateStr, $m)) {
        $hour = (int)$m[2];
        $min = $m[3];
        $ampm = $hour < 12 ? 'AM' : 'PM';
        if ($hour == 0) $hour = 12;
        elseif ($hour > 12) $hour -= 12;
        return trim($m[1]) . ' ' . $hour . ':' . $min . ' ' . $ampm;
    }
    return $dateStr;
}

$daysRemaining = null;
$isExpired = false;
$isUpcoming = false;
$expiresText = '';

if ($permit && isset($permit['validTo'])) {
    $dateStr = preg_replace('/:\s*\d{1,2}:\d{2}$/', '', $permit['validTo']);
    $validTo = DateTime::createFromFormat('M j, Y', trim($dateStr));

    // Parse validFrom to check if permit hasn't started yet
    $validFrom = null;
    if (isset($permit['validFrom'])) {
        $fromDateStr = preg_replace('/:\s*\d{1,2}:\d{2}$/', '', $permit['validFrom']);
        $validFrom = DateTime::createFromFormat('M j, Y', trim($fromDateStr));
    }

    if ($validTo) {
        $validTo->setTime(23, 59, 59);
        $now = new DateTime();
        $today = new DateTime('today');
        $expiryDay = new DateTime($validTo->format('Y-m-d'));

        $diff = $today->diff($expiryDay);
        $daysUntilExpiry = $diff->invert ? -1 : $diff->days;

        // Check if permit hasn't started yet
        if ($validFrom) {
            $validFrom->setTime(0, 0, 0);
            if ($now < $validFrom) {
                $isUpcoming = true;
                $startDiff = $today->diff(new DateTime($validFrom->format('Y-m-d')));
                $daysUntilStart = $startDiff->days;
                if ($daysUntilStart == 0) {
                    $expiresText = 'Starts Today';
                } elseif ($daysUntilStart == 1) {
                    $expiresText = 'Starts Tomorrow';
                } else {
                    $expiresText = 'Starts in ' . $daysUntilStart . ' days';
                }
            }
        }

        if (!$isUpcoming) {
            if ($now > $validTo) {
                $isExpired = true;
                $daysRemaining = 0;
                $expiresText = 'Expired';
            } elseif ($daysUntilExpiry == 0) {
                $daysRemaining = 0;
                $expiresText = 'Expires Today';
            } elseif ($daysUntilExpiry == 1) {
                $daysRemaining = 1;
                $expiresText = 'Expires Tomorrow';
            } else {
                $daysRemaining = $daysUntilExpiry;
                $expiresText = $daysRemaining . ' days remaining';
            }
        }
    }
}

$statusColor = '#4caf50';
$statusText = 'Valid';
if ($isExpired) {
    $statusColor = '#f44336';
    $statusText = 'Expired';
} elseif ($isUpcoming) {
    $statusColor = '#2196f3';
    $statusText = 'Upcoming';
} elseif ($daysRemaining !== null && $daysRemaining <= 1) {
    $statusColor = '#ff9800';
    $statusText = 'Expiring Soon';
}

// Historical badge overrides
if ($isHistorical) {
    $statusColor = '#607d8b';
    $statusText = 'Historical';
}

// Get amount paid
$amountPaid = $permit['amountPaid'] ?? null;

// Other permits that are still relevant (active or upcoming) besides the displayed one,
// e.g. an old vehicle's permit on its last day while the new vehicle's is upcoming.
$otherPermits = [];
if (!$requestedPermit && $permit && file_exists($historyFile)) {
    $history = json_decode(file_get_contents($historyFile), true) ?: [];
    $now = new DateTime();
    $today = new DateTime('today');
    $seen = [$permit['permitNumber'] ?? null];
    foreach (array_reverse($history) as $p) {
        $num = $p['permitNumber'] ?? null;
        if (!$num || in_array($num, $seen, true)) continue;
        $vf = parsePermitDt($p['validFrom'] ?? '');
        $vt = parsePermitDt($p['validTo'] ?? '');
        if (!$vf || !$vt) continue;
        $vt->setTime(23, 59, 59);
        if ($now > $vt) continue;
        $seen[] = $num;
        $vf->setTime(0, 0, 0);
        if ($now < $vf) {
            $status = ['text' => 'Upcoming', 'color' => '#2196f3'];
        } else {
            $expiryDay = new DateTime($vt->format('Y-m-d'));
            $diff = $today->diff($expiryDay);
            $days = $diff->invert ? -1 : $diff->days;
            if ($days == 0) $status = ['text' => 'Expires Today', 'color' => '#f44336'];
            elseif ($days == 1) $status = ['text' => 'Expires Tomorrow', 'color' => '#ff9800'];
            else $status = ['text' => $days . ' days left', 'color' => '#4caf50'];
        }
        $name = null;
        foreach ($cars as $car) {
            if (strtoupper($car['plate']) === strtoupper($p['plateNumber'] ?? '')) {
                $name = $car['name'];
                break;
            }
        }
        $otherPermits[] = ['permit' => $p, 'name' => $name, 'status' => $status];
    }
}

// Helper function to check if permit is a weekly permit (approximately 7 days)
function isWeeklyPermit($p) {
    $from = $p['validFrom'] ?? '';
    $to = $p['validTo'] ?? '';
    $from = preg_replace('/:\s*\d{1,2}:\d{2}$/', '', $from);
    $to = preg_replace('/:\s*\d{1,2}:\d{2}$/', '', $to);
    $fromDate = DateTime::createFromFormat('M j, Y', trim($from));
    $toDate = DateTime::createFromFormat('M j, Y', trim($to));
    if (!$fromDate || !$toDate) return false;
    return $fromDate->diff($toDate)->days >= 6;
}

// Calculate price change for current permit (not historical, only weekly permits)
$priceChangeAmount = null;
$priceChangeDate = null;
if ($permit && !$isHistorical && file_exists($historyFile) && isWeeklyPermit($permit)) {
    $permits = json_decode(file_get_contents($historyFile), true) ?: [];
    if (count($permits) >= 2) {
        $currentPrice = floatval(str_replace(['$', ','], '', $permit['amountPaid'] ?? '0'));

        // Find the current permit's index in history
        $currentIndex = -1;
        for ($i = count($permits) - 1; $i >= 0; $i--) {
            if (($permits[$i]['permitNumber'] ?? '') === ($permit['permitNumber'] ?? '')) {
                $currentIndex = $i;
                break;
            }
        }

        // Look backwards from current permit to find when price changed (only compare weekly permits)
        if ($currentIndex > 0) {
            for ($i = $currentIndex - 1; $i >= 0; $i--) {
                // Skip non-weekly permits
                if (!isWeeklyPermit($permits[$i])) continue;

                $prevPrice = floatval(str_replace(['$', ','], '', $permits[$i]['amountPaid'] ?? '0'));
                if ($prevPrice != $currentPrice && $prevPrice > 0) {
                    $priceChangeAmount = $currentPrice - $prevPrice;
                    // Find the first weekly permit at the new price
                    for ($j = $i + 1; $j <= $currentIndex; $j++) {
                        if (isWeeklyPermit($permits[$j])) {
                            $priceChangeDate = $permits[$j]['validFrom'] ?? null;
                            if ($priceChangeDate) {
                                $priceChangeDate = preg_replace('/:\s*\d{1,2}:\d{2}$/', '', $priceChangeDate);
                            }
                            break;
                        }
                    }
                    // Only show price change for the first week
                    if ($priceChangeDate) {
                        $changeDate = DateTime::createFromFormat('M j, Y', trim($priceChangeDate));
                        if ($changeDate && (new DateTime())->diff($changeDate)->days > 7) {
                            $priceChangeAmount = null;
                            $priceChangeDate = null;
                        }
                    }
                    break;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/svg+xml" href="<?= $urlBase ?>/static/favicon.svg">
    <title>Parking Permit Status</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            height: 100%;
            overflow: hidden;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1f2e;
            color: #e2e8f0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 16px;
        }
        .card {
            background: #2a3142;
            border-radius: 16px;
            padding: 28px;
            max-width: 400px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            gap: 12px;
        }
        .title {
            font-size: 22px;
            font-weight: 700;
            color: #fff;
            white-space: nowrap;
        }
        .status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: <?= $statusColor ?>;
            color: white;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #3a4255;
        }
        .info-row:last-child { border-bottom: none; }
        .label { color: #8892a6; font-size: 14px; }
        .value { font-weight: 500; color: #e2e8f0; text-align: right; }
        .value.price { color: #4caf50; }
        .price-change {
            font-size: 12px;
            margin-top: 4px;
        }
        .price-change.up { color: #f44336; }
        .price-change.down { color: #4caf50; }
        .days-remaining {
            text-align: center;
            margin-top: 20px;
            padding: 16px;
            background: #1e2433;
            border-radius: 12px;
        }
        .days-text {
            font-size: 22px;
            font-weight: 700;
            color: <?= $statusColor ?>;
        }
        .no-permit {
            text-align: center;
            color: #8892a6;
            padding: 40px;
        }
        .other-permits { margin-top: 14px; }
        .other-permits-title {
            color: #8892a6;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .other-permit {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #1e2433;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 8px;
            text-decoration: none;
            color: #e2e8f0;
            font-size: 14px;
        }
        .other-permit:last-child { margin-bottom: 0; }
        .other-permit:hover { background: #232a3c; }
        .other-permit-plate { color: #8892a6; font-size: 12px; }
        .other-permit-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            color: white;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .links {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 12px 14px;
            margin-top: 14px;
            padding-bottom: 20px;
        }
        .link {
            color: #64b5f6;
            text-decoration: none;
            font-size: 14px;
        }
        .link:hover { text-decoration: underline; }
        .project-links {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin: 12px auto 0;
            font-size: 11px;
        }
        .project-links a {
            color: #5a6378;
            text-decoration: none;
        }
        .project-links a:hover {
            color: #8892a6;
            text-decoration: underline;
        }
        .autobuyer-warning {
            background: #1e2433;
            border-left: 4px solid #ff9800;
            padding: 10px 14px;
            margin-bottom: 16px;
            border-radius: 0 8px 8px 0;
            font-size: 13px;
            color: #ffb74d;
        }
        .autobuyer-warning a {
            color: #64b5f6;
            text-decoration: none;
        }
        .autobuyer-warning a:hover {
            text-decoration: underline;
        }

        /* Mobile Responsive - fit without scrolling */
        @media (max-width: 400px) {
            body {
                padding: 10px;
            }
            .card {
                padding: 20px;
            }
            .header {
                margin-bottom: 16px;
            }
            .title {
                font-size: 20px;
            }
            .status {
                padding: 5px 10px;
                font-size: 11px;
            }
            .info-row {
                padding: 8px 0;
            }
            .label {
                font-size: 13px;
            }
            .value {
                font-size: 13px;
            }
            .days-remaining {
                margin-top: 16px;
                padding: 14px;
            }
            .days-text {
                font-size: 20px;
            }
            .links {
                margin-top: 12px;
            }
            .link {
                font-size: 13px;
            }
        }

        /* Extra small screens */
        @media (max-height: 600px) {
            .card {
                padding: 16px;
            }
            .header {
                margin-bottom: 12px;
            }
            .info-row {
                padding: 6px 0;
            }
            .days-remaining {
                margin-top: 12px;
                padding: 12px;
            }
            .days-text {
                font-size: 18px;
            }
            .links {
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="card">
        <?php if (!$autobuyerEnabled): ?>
            <?php
            $disabledUntil = $settings['autobuyer']['disabled_until'] ?? null;
            $untilLabel = ($disabledUntil && preg_match('/^\d{4}-\d{2}-\d{2}$/', $disabledUntil))
                ? date('M j', strtotime($disabledUntil)) : null;
            ?>
            <div class="autobuyer-warning">
                <?php if ($untilLabel): ?>
                    Auto-buyer is disabled until <strong><?= htmlspecialchars($untilLabel) ?></strong>. <a href="<?= $urlBase ?>/settings/">Settings</a>
                <?php else: ?>
                    Auto-buyer is disabled. <a href="<?= $urlBase ?>/settings/">Enable it</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($permit): ?>
            <div class="header">
                <span class="title">Parking Permit</span>
                <span class="status"><?= $statusText ?></span>
            </div>
            <?php if ($nickname): ?>
            <div class="info-row">
                <span class="label">Vehicle</span>
                <span class="value"><?= htmlspecialchars($nickname) ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="label">Plate</span>
                <span class="value"><?= htmlspecialchars($permit['plateNumber'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row">
                <span class="label">Permit #</span>
                <span class="value"><?= htmlspecialchars($permit['permitNumber'] ?? 'N/A') ?></span>
            </div>
            <?php if ($amountPaid): ?>
            <div class="info-row">
                <span class="label">Cost</span>
                <span class="value price">
                    <?= htmlspecialchars($amountPaid) ?>
                    <?php if ($priceChangeAmount !== null && $priceChangeDate): ?>
                        <div class="price-change <?= $priceChangeAmount > 0 ? 'up' : 'down' ?>">
                            <?= $priceChangeAmount > 0 ? '+' : '' ?>$<?= number_format(abs($priceChangeAmount), 2) ?> since <?= htmlspecialchars($priceChangeDate) ?>
                        </div>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="label">Valid From</span>
                <span class="value"><?= htmlspecialchars(formatDateTime($permit['validFrom'] ?? '')) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Valid To</span>
                <span class="value"><?= htmlspecialchars(formatDateTime($permit['validTo'] ?? '')) ?></span>
            </div>
            <div class="days-remaining">
                <div class="days-text"><?= $expiresText ?></div>
            </div>
            <?php if ($otherPermits): ?>
            <div class="other-permits">
                <div class="other-permits-title">Other permits</div>
                <?php foreach ($otherPermits as $op): ?>
                <a class="other-permit" href="<?= $urlBase ?>/?permit=<?= urlencode($op['permit']['permitNumber']) ?>">
                    <span>
                        <?= htmlspecialchars($op['name'] ?? $op['permit']['plateNumber'] ?? 'Unknown') ?>
                        <?php if ($op['name'] && !empty($op['permit']['plateNumber'])): ?>
                            <span class="other-permit-plate">(<?= htmlspecialchars($op['permit']['plateNumber']) ?>)</span>
                        <?php endif; ?>
                    </span>
                    <span class="other-permit-status" style="background: <?= $op['status']['color'] ?>"><?= $op['status']['text'] ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="links">
                <?php if ($isHistorical): ?>
                    <a href="<?= $urlBase ?>/" class="link">View Current</a>
                <?php endif; ?>
                <a href="<?= $urlBase ?>/history/" class="link">Permit History</a>
                <a href="<?= $urlBase ?>/prices/" class="link">Price History</a>
            </div>
        <?php else: ?>
            <div class="no-permit">No permit data found</div>
            <div class="links">
                <a href="<?= $urlBase ?>/history/" class="link">Permit History</a>
            </div>
        <?php endif; ?>
    </div>
    <div class="project-links">
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-buyer" target="_blank">Auto-buyer</a>
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-display" target="_blank">E-ink Display</a>
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-android" target="_blank">Android App</a>
    </div>
    <?php include __DIR__ . '/_partials/bottom_nav.php'; ?>
    <?php include __DIR__ . '/_partials/version_banner.php'; ?>
    <?php include __DIR__ . '/_partials/console_easter_egg.php'; ?>
    <script>
        // Keep the page fresh: reload when returning to a stale tab, and every 5 min while open
        (function() {
            var MAX_AGE_MS = 5 * 60 * 1000;
            var loadedAt = Date.now();
            function reloadIfStale() {
                if (!document.hidden && Date.now() - loadedAt > MAX_AGE_MS) {
                    location.reload();
                }
            }
            document.addEventListener('visibilitychange', reloadIfStale);
            setInterval(reloadIfStale, 60 * 1000);
        })();
    </script>
</body>
</html>
