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

// Fall back to current permit if not found or not requested
if (!$permit && $currentPermit) {
    $permit = $currentPermit;
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
            margin-top: 12px;
            max-width: 400px;
            width: 100%;
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
            <div class="autobuyer-warning">
                Auto-buyer is disabled. <a href="<?= $urlBase ?>/settings/">Enable it</a>
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
    <script>
        // A special message for those who look
        console.log(`%c
    ╭─────────────────────────────────────────╮
    │                                         │
    │      🖕 CITY OF TORONTO PARKING 🖕      │
    │                                         │
    │   "The only thing more expensive than   │
    │    Toronto parking is Toronto rent"     │
    │                                         │
    │   So I automated the whole thing...     │
    │                                         │
    ╰─────────────────────────────────────────╯

🖕  https://media.giphy.com/media/xndHaRIcvge5y/giphy.gif

If you're already here, check out my other automated bullshit:
🖕 Auto-buyer: https://github.com/VisTechProjects/parking-permit-buyer
🖕 E-ink display: https://github.com/VisTechProjects/parking-permit-display
        `, 'color: #4caf50; font-family: monospace;');
    </script>
</body>
</html>
