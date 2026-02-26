<?php
require_once __DIR__ . '/../config.php';

$permits = [];
if (file_exists($historyFile)) {
    $permits = json_decode(file_get_contents($historyFile), true) ?: [];
}

// Load settings to check if autobuyer is enabled
$settings = [];
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
}
$autobuyerEnabled = $settings['autobuyer']['enabled'] ?? true;

// Load current permit to calculate next scheduled
$currentPermit = null;
if (file_exists($permitFile)) {
    $currentPermit = json_decode(file_get_contents($permitFile), true);
}

// Load car nicknames
$cars = [];
if (file_exists($carsFile)) {
    $cars = json_decode(file_get_contents($carsFile), true) ?: [];
}

function getNickname($plate, $cars) {
    foreach ($cars as $car) {
        if (strtoupper($car['plate']) === strtoupper($plate)) {
            return $car['name'];
        }
    }
    return null;
}

function formatDate($dateStr) {
    if (preg_match('/^(.+):\s*\d{1,2}:\d{2}$/', $dateStr, $m)) {
        return trim($m[1]);
    }
    return $dateStr;
}

function parseDate($dateStr) {
    $dateStr = preg_replace('/:\s*\d{1,2}:\d{2}$/', '', $dateStr);
    return DateTime::createFromFormat('M j, Y', trim($dateStr));
}

function getPermitStatus($validFrom, $validTo) {
    $validToDate = parseDate($validTo);
    $validFromDate = parseDate($validFrom);

    if (!$validToDate) {
        return ['text' => 'Unknown', 'class' => 'expired', 'color' => '#607d8b'];
    }

    $validToDate->setTime(23, 59, 59);
    $now = new DateTime();
    $today = new DateTime('today');

    // Check if permit hasn't started yet
    if ($validFromDate) {
        $validFromDate->setTime(0, 0, 0);
        if ($now < $validFromDate) {
            return ['text' => 'Upcoming', 'class' => 'upcoming', 'color' => '#2196f3'];
        }
    }

    $expiryDay = new DateTime($validToDate->format('Y-m-d'));
    $diff = $today->diff($expiryDay);
    $daysUntilExpiry = $diff->invert ? -1 : $diff->days;

    if ($now > $validToDate) {
        return ['text' => 'Expired', 'class' => 'expired', 'color' => '#607d8b'];
    } elseif ($daysUntilExpiry == 0) {
        return ['text' => 'Expires Today', 'class' => 'expires-today', 'color' => '#f44336'];
    } elseif ($daysUntilExpiry <= 1) {
        return ['text' => 'Expiring Soon', 'class' => 'expiring', 'color' => '#ff9800'];
    } else {
        return ['text' => 'Current', 'class' => 'current', 'color' => '#4caf50'];
    }
}

// Calculate next scheduled permit based on current permit expiry (only if autobuyer enabled)
$scheduledPermit = null;
if ($autobuyerEnabled && $currentPermit && isset($currentPermit['validTo'])) {
    $currentExpiry = parseDate($currentPermit['validTo']);
    if ($currentExpiry) {
        // Next permit starts day after current expires
        $nextStart = clone $currentExpiry;
        $nextStart->modify('+1 day');
        $nextEnd = clone $nextStart;
        $nextEnd->modify('+6 days'); // 7 day permit

        // Get latest weekly permit price
        $latestWeeklyPrice = null;
        for ($i = count($permits) - 1; $i >= 0; $i--) {
            $p = $permits[$i];
            $from = parseDate($p['validFrom'] ?? '');
            $to = parseDate($p['validTo'] ?? '');
            if ($from && $to && $from->diff($to)->days >= 6) {
                $latestWeeklyPrice = $p['amountPaid'] ?? null;
                break;
            }
        }

        $scheduledPermit = [
            'permitNumber' => 'Pending',
            'plateNumber' => $currentPermit['plateNumber'] ?? '',
            'validFrom' => $nextStart->format('M j, Y'),
            'validTo' => $nextEnd->format('M j, Y'),
            'isScheduled' => true,
            'amountPaid' => $latestWeeklyPrice
        ];
    }
}

// Helper function to check if permit is a weekly permit
function isWeeklyPermit($permit) {
    $from = parseDate($permit['validFrom'] ?? '');
    $to = parseDate($permit['validTo'] ?? '');
    if (!$from || !$to) return false;
    return $from->diff($to)->days >= 6;
}

// Build price change map - tracks which permits first introduced a new price
$priceChangeMap = [];
$previousPrice = null;
foreach ($permits as $i => $permit) {
    if (!isWeeklyPermit($permit)) continue;
    $price = floatval(str_replace(['$', ','], '', $permit['amountPaid'] ?? '0'));
    if ($price <= 0) continue;

    if ($previousPrice !== null && $price != $previousPrice) {
        // This permit is the first at a new price
        $priceChangeMap[$permit['permitNumber'] ?? ''] = $price - $previousPrice;
    }
    $previousPrice = $price;
}

// Get unique plates for filter dropdown
$uniquePlates = [];
foreach ($permits as $permit) {
    $plate = $permit['plateNumber'] ?? '';
    if ($plate && !isset($uniquePlates[$plate])) {
        $nickname = getNickname($plate, $cars);
        $uniquePlates[$plate] = $nickname ? "$nickname ($plate)" : $plate;
    }
}

// Total count for display
$totalPermits = count($permits) + ($scheduledPermit ? 1 : 0);

// Calculate total spent (all time)
$totalSpent = 0;
foreach ($permits as $permit) {
    if (isset($permit['amountPaid'])) {
        $totalSpent += floatval(str_replace(['$', ','], '', $permit['amountPaid']));
    }
}

// Reverse to show newest first
$permits = array_reverse($permits);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permit History</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1f2e;
            color: #e2e8f0;
            min-height: 100vh;
            padding: 16px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
        }
        h1 {
            text-align: center;
            margin-bottom: 16px;
            font-size: 22px;
            color: #fff;
        }
        /* Filter Bar */
        .filter-bar {
            background: #2a3142;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .filter-group {
            flex: 1;
            min-width: 100px;
        }
        .filter-group label {
            display: block;
            font-size: 10px;
            color: #8892a6;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .filter-group select {
            width: 100%;
            padding: 8px 10px;
            background: #1a1f2e;
            border: 1px solid #3d4659;
            border-radius: 6px;
            color: #e2e8f0;
            font-size: 13px;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%238892a6' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }
        .filter-group select:focus {
            outline: none;
            border-color: #64b5f6;
        }
        .clear-filters {
            align-self: flex-end;
            padding: 8px 12px;
            background: transparent;
            border: 1px solid #3d4659;
            border-radius: 6px;
            color: #8892a6;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .clear-filters:hover:not(:disabled) {
            border-color: #64b5f6;
            color: #64b5f6;
        }
        .clear-filters:disabled {
            opacity: 0.4;
            cursor: default;
        }
        /* Permit Count */
        .permit-count {
            text-align: center;
            font-size: 12px;
            color: #8892a6;
            margin-bottom: 4px;
        }
        .total-spent-line {
            text-align: center;
            font-size: 12px;
            color: #4caf50;
            font-weight: 500;
            margin-bottom: 12px;
        }
        .autobuyer-warning {
            background: #1e2433;
            border-left: 4px solid #ff9800;
            padding: 12px 16px;
            margin-bottom: 16px;
            border-radius: 0 8px 8px 0;
            font-size: 13px;
            color: #ffb74d;
        }
        .autobuyer-warning a {
            color: #64b5f6;
            text-decoration: none;
            margin-left: 8px;
        }
        .autobuyer-warning a:hover {
            text-decoration: underline;
        }
        /* Permit Cards */
        .permit-card {
            background: #2a3142;
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-decoration: none;
            color: inherit;
            transition: background 0.2s;
        }
        .permit-card:hover {
            background: #343b4f;
        }
        .permit-card.scheduled {
            border: 2px dashed #5c6bc0;
            background: #252a3a;
            cursor: default;
        }
        .permit-card.scheduled:hover {
            background: #252a3a;
        }
        .permit-card.hidden {
            display: none;
        }
        .permit-info { flex: 1; }
        .permit-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 2px;
        }
        .permit-number {
            font-size: 16px;
            font-weight: 600;
            color: #fff;
        }
        .permit-price {
            font-size: 12px;
            color: #4caf50;
            font-weight: 500;
        }
        .price-change {
            font-size: 11px;
            font-weight: 500;
            margin-left: 6px;
        }
        .price-change.up { color: #f44336; }
        .price-change.down { color: #4caf50; }
        .permit-dates {
            font-size: 13px;
            color: #8892a6;
        }
        .permit-vehicle {
            font-size: 11px;
            color: #64b5f6;
            margin-top: 2px;
        }
        .status-badge {
            padding: 5px 10px;
            border-radius: 16px;
            font-size: 11px;
            font-weight: 600;
            color: white;
            white-space: nowrap;
            margin-left: 10px;
            flex-shrink: 0;
        }
        .current { background: #4caf50; }
        .upcoming { background: #2196f3; }
        .expiring { background: #ff9800; }
        .expires-today { background: #f44336; }
        .expired { background: #607d8b; }
        .scheduled-badge { background: #5c6bc0; }
        .empty {
            text-align: center;
            color: #8892a6;
            padding: 30px;
        }
        .no-results {
            text-align: center;
            color: #8892a6;
            padding: 30px;
            display: none;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 14px;
            color: #64b5f6;
            text-decoration: none;
            font-size: 14px;
        }
        .back-link:hover { text-decoration: underline; }

        /* Mobile Responsive */
        @media (max-width: 500px) {
            body {
                padding: 10px;
            }
            h1 {
                font-size: 20px;
                margin-bottom: 12px;
            }
            .filter-bar {
                padding: 10px;
                gap: 8px;
            }
            .filter-group {
                flex: 1 1 calc(50% - 4px);
                min-width: calc(50% - 4px);
            }
            .filter-group:nth-child(3) {
                flex: 1 1 100%;
            }
            .clear-filters {
                flex: 1 1 100%;
            }
            .permit-card {
                padding: 12px;
            }
            .permit-number {
                font-size: 15px;
            }
            .permit-dates {
                font-size: 12px;
            }
            .status-badge {
                padding: 4px 8px;
                font-size: 10px;
            }
            .permit-count {
                font-size: 11px;
                margin-bottom: 10px;
            }
            .back-link {
                margin-top: 12px;
                font-size: 13px;
            }
        }
        .project-links {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-top: 12px;
            max-width: 800px;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Permit History</h1>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label for="filterVehicle">Vehicle</label>
                <select id="filterVehicle">
                    <option value="">All Vehicles</option>
                    <?php foreach ($uniquePlates as $plate => $display): ?>
                        <option value="<?= htmlspecialchars($plate) ?>"><?= htmlspecialchars($display) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="filterStatus">Status</label>
                <select id="filterStatus">
                    <option value="">All Statuses</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="upcoming">Upcoming</option>
                    <option value="current">Current</option>
                    <option value="expiring">Expiring Soon</option>
                    <option value="expires-today">Expires Today</option>
                    <option value="expired">Expired</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="filterDate">Date Range</label>
                <select id="filterDate">
                    <option value="">All Time</option>
                    <option value="30">Last 30 Days</option>
                    <option value="90">Last 90 Days</option>
                    <option value="180">Last 6 Months</option>
                    <option value="365">Last Year</option>
                </select>
            </div>
            <button class="clear-filters" id="clearBtn" onclick="clearFilters()" disabled>Clear Filters</button>
        </div>

        <!-- Permit Count -->
        <div class="permit-count" id="permitCount">
            Showing <span id="visibleCount"><?= $totalPermits ?></span> of <?= $totalPermits ?> permits
        </div>
        <?php if ($totalSpent > 0): ?>
            <div class="total-spent-line">Money wasted on Toronto parking: <span style="color: #f44336;">$<?= number_format($totalSpent, 2) ?></span></div>
        <?php endif; ?>

        <?php if (!$autobuyerEnabled): ?>
            <div class="autobuyer-warning">
                Auto-buyer is disabled. Permits will NOT be purchased automatically.
                <a href="<?= $urlBase ?>/settings/">Enable it</a>
            </div>
        <?php endif; ?>

        <div id="permitsList">
            <?php if ($scheduledPermit): ?>
                <div class="permit-card scheduled" data-plate="<?= htmlspecialchars($scheduledPermit['plateNumber']) ?>" data-status="scheduled" data-date="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                    <div class="permit-info">
                        <div class="permit-header">
                            <span class="permit-number"><?= htmlspecialchars($scheduledPermit['permitNumber']) ?></span>
                            <?php if (!empty($scheduledPermit['amountPaid'])): ?>
                                <span class="permit-price"><?= htmlspecialchars($scheduledPermit['amountPaid']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="permit-dates">
                            <?= htmlspecialchars($scheduledPermit['validFrom']) ?> - <?= htmlspecialchars($scheduledPermit['validTo']) ?>
                        </div>
                        <?php $nickname = getNickname($scheduledPermit['plateNumber'], $cars); ?>
                        <?php if ($nickname): ?>
                            <div class="permit-vehicle"><?= htmlspecialchars($nickname) ?> (<?= htmlspecialchars($scheduledPermit['plateNumber']) ?>)</div>
                        <?php endif; ?>
                    </div>
                    <span class="status-badge scheduled-badge">Scheduled</span>
                </div>
            <?php endif; ?>

            <?php if (empty($permits) && !$scheduledPermit): ?>
                <div class="empty">No permit history found</div>
            <?php else: ?>
                <?php foreach ($permits as $index => $permit): ?>
                    <?php
                    $status = getPermitStatus($permit['validFrom'] ?? '', $permit['validTo'] ?? '');
                    $validFrom = parseDate($permit['validFrom'] ?? '');
                    $dateAttr = $validFrom ? $validFrom->format('Y-m-d') : '';
                    ?>
                    <a href="<?= $urlBase ?>/?permit=<?= htmlspecialchars($permit['permitNumber'] ?? '') ?>"
                       class="permit-card"
                       data-plate="<?= htmlspecialchars($permit['plateNumber'] ?? '') ?>"
                       data-status="<?= $status['class'] ?>"
                       data-date="<?= $dateAttr ?>">
                        <div class="permit-info">
                            <div class="permit-header">
                                <span class="permit-number"><?= htmlspecialchars($permit['permitNumber'] ?? 'N/A') ?></span>
                                <?php if (!empty($permit['amountPaid'])): ?>
                                    <span class="permit-price"><?= htmlspecialchars($permit['amountPaid']) ?><?php
                                        $permitNum = $permit['permitNumber'] ?? '';
                                        if (isset($priceChangeMap[$permitNum])):
                                            $change = $priceChangeMap[$permitNum];
                                        ?><span class="price-change <?= $change > 0 ? 'up' : 'down' ?>">(<?= $change > 0 ? '+' : '' ?>$<?= number_format(abs($change), 2) ?>)</span><?php endif; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="permit-dates">
                                <?= htmlspecialchars(formatDate($permit['validFrom'] ?? '')) ?> - <?= htmlspecialchars(formatDate($permit['validTo'] ?? '')) ?>
                            </div>
                            <?php $nickname = getNickname($permit['plateNumber'] ?? '', $cars); ?>
                            <?php if ($nickname): ?>
                                <div class="permit-vehicle"><?= htmlspecialchars($nickname) ?> (<?= htmlspecialchars($permit['plateNumber'] ?? '') ?>)</div>
                            <?php endif; ?>
                        </div>
                        <span class="status-badge <?= $status['class'] ?>">
                            <?= $status['text'] ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="no-results" id="noResults">No permits match your filters</div>
        <div style="display: flex; justify-content: center; gap: 20px; margin-top: 16px; padding-bottom: 20px;">
            <a href="<?= $urlBase ?>/" class="back-link">Current Permit</a>
            <a href="<?= $urlBase ?>/prices/" class="back-link">Price History</a>
            <a href="<?= $urlBase ?>/settings/" class="back-link">Settings</a>
        </div>
    </div>

    <script>
        const filterVehicle = document.getElementById('filterVehicle');
        const filterStatus = document.getElementById('filterStatus');
        const filterDate = document.getElementById('filterDate');
        const clearBtn = document.getElementById('clearBtn');
        const noResults = document.getElementById('noResults');
        const visibleCountEl = document.getElementById('visibleCount');
        const totalPermits = <?= $totalPermits ?>;

        function updateClearButton() {
            const hasFilters = filterVehicle.value || filterStatus.value || filterDate.value;
            clearBtn.disabled = !hasFilters;
        }

        function applyFilters() {
            const vehicle = filterVehicle.value;
            const status = filterStatus.value;
            const days = filterDate.value ? parseInt(filterDate.value) : 0;

            const cards = document.querySelectorAll('.permit-card');
            let visibleCount = 0;

            const cutoffDate = days ? new Date(Date.now() - days * 24 * 60 * 60 * 1000) : null;

            cards.forEach(card => {
                let show = true;

                // Vehicle filter
                if (vehicle && card.dataset.plate !== vehicle) {
                    show = false;
                }

                // Status filter (expiring also shows expires-today)
                if (status) {
                    const cardStatus = card.dataset.status;
                    if (status === 'expiring') {
                        if (cardStatus !== 'expiring' && cardStatus !== 'expires-today') {
                            show = false;
                        }
                    } else if (cardStatus !== status) {
                        show = false;
                    }
                }

                // Date filter (based on permit start date)
                if (cutoffDate && card.dataset.date) {
                    const cardDate = new Date(card.dataset.date);
                    if (cardDate < cutoffDate) {
                        show = false;
                    }
                }

                card.classList.toggle('hidden', !show);
                if (show) visibleCount++;
            });

            visibleCountEl.textContent = visibleCount;
            noResults.style.display = visibleCount === 0 ? 'block' : 'none';
            updateClearButton();
        }

        function clearFilters() {
            filterVehicle.value = '';
            filterStatus.value = '';
            filterDate.value = '';
            applyFilters();
        }

        filterVehicle.addEventListener('change', applyFilters);
        filterStatus.addEventListener('change', applyFilters);
        filterDate.addEventListener('change', applyFilters);

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
    <div class="project-links">
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-buyer" target="_blank">Auto-buyer</a>
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-display" target="_blank">E-ink Display</a>
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-android" target="_blank">Android App</a>
    </div>
</body>
</html>
