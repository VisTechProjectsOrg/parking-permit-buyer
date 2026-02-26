<?php
require_once __DIR__ . '/../config.php';

$permits = [];
if (file_exists($historyFile)) {
    $permits = json_decode(file_get_contents($historyFile), true) ?: [];
}

// Helper function to check if permit is a weekly permit (approximately 7 days)
function isWeeklyPermit($permit) {
    $from = $permit['validFrom'] ?? '';
    $to = $permit['validTo'] ?? '';

    // Extract just the date parts
    $from = preg_replace('/:\s*\d{1,2}:\d{2}$/', '', $from);
    $to = preg_replace('/:\s*\d{1,2}:\d{2}$/', '', $to);

    $fromDate = DateTime::createFromFormat('M j, Y', trim($from));
    $toDate = DateTime::createFromFormat('M j, Y', trim($to));

    if (!$fromDate || !$toDate) return false;

    $diff = $fromDate->diff($toDate)->days;
    // Weekly permits are 6-7 days (sometimes 6 due to timing)
    return $diff >= 6;
}

// Build price change history (only weekly permits)
$priceChanges = [];
$previousPrice = null;

foreach ($permits as $i => $permit) {
    // Skip non-weekly permits
    if (!isWeeklyPermit($permit)) continue;

    $price = floatval(str_replace(['$', ','], '', $permit['amountPaid'] ?? '0'));
    if ($price <= 0) continue;

    $date = $permit['validFrom'] ?? '';
    $date = preg_replace('/:\s*\d{1,2}:\d{2}$/', '', $date);

    if ($previousPrice === null) {
        // First weekly permit - record as baseline
        $priceChanges[] = [
            'date' => $date,
            'price' => $price,
            'change' => null,
            'permitNumber' => $permit['permitNumber'] ?? '',
            'isFirst' => true
        ];
    } elseif ($price != $previousPrice) {
        // Price changed
        $priceChanges[] = [
            'date' => $date,
            'price' => $price,
            'change' => $price - $previousPrice,
            'permitNumber' => $permit['permitNumber'] ?? '',
            'isFirst' => false
        ];
    }

    $previousPrice = $price;
}

// Reverse to show newest first
$priceChanges = array_reverse($priceChanges);

// Calculate stats
$totalChanges = count($priceChanges) - 1; // Exclude the baseline
$totalIncrease = 0;
$firstPrice = null;
$currentPrice = null;

foreach ($priceChanges as $change) {
    if ($change['isFirst']) {
        $firstPrice = $change['price'];
    }
    if ($currentPrice === null) {
        $currentPrice = $change['price'];
    }
    if ($change['change'] !== null) {
        $totalIncrease += $change['change'];
    }
}

$percentChange = $firstPrice > 0 ? (($currentPrice - $firstPrice) / $firstPrice) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price History</title>
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
            max-width: 500px;
            margin: 0 auto;
        }
        .card {
            background: #2a3142;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
        }
        .header {
            font-size: 20px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 4px;
        }
        .subtitle {
            font-size: 12px;
            color: #8892a6;
            margin-bottom: 16px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .stat {
            background: #1e2433;
            padding: 14px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-value {
            font-size: 22px;
            font-weight: 700;
            color: #fff;
        }
        .stat-value.up { color: #f44336; }
        .stat-value.down { color: #4caf50; }
        .stat-label {
            font-size: 12px;
            color: #8892a6;
            margin-top: 4px;
        }
        .change-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #3a4255;
        }
        .change-item:last-child { border-bottom: none; }
        .change-date {
            color: #8892a6;
            font-size: 14px;
        }
        .change-permit {
            font-size: 11px;
            color: #64b5f6;
            margin-top: 2px;
            text-decoration: none;
            display: block;
        }
        .change-permit:hover {
            text-decoration: underline;
        }
        .change-info {
            text-align: right;
        }
        .change-price {
            font-weight: 600;
            color: #e2e8f0;
        }
        .change-amount {
            font-size: 13px;
            margin-top: 2px;
        }
        .change-amount.up { color: #f44336; }
        .change-amount.down { color: #4caf50; }
        .change-amount.baseline { color: #8892a6; }
        .links {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 16px;
            padding-bottom: 20px;
        }
        .link {
            color: #64b5f6;
            text-decoration: none;
            font-size: 14px;
        }
        .link:hover { text-decoration: underline; }
        .empty {
            text-align: center;
            color: #8892a6;
            padding: 30px;
        }
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
    </style>
</head>
<body>
    <div class="container">
        <?php if (count($priceChanges) > 0): ?>
        <div class="card">
            <div class="header" style="margin-bottom: 16px;">Price Statistics</div>
            <div class="stats-grid">
                <div class="stat">
                    <div class="stat-value">$<?= number_format($currentPrice, 2) ?></div>
                    <div class="stat-label">Current Price</div>
                </div>
                <div class="stat">
                    <div class="stat-value <?= $totalIncrease > 0 ? 'up' : ($totalIncrease < 0 ? 'down' : '') ?>">
                        <?= $totalIncrease >= 0 ? '+' : '' ?>$<?= number_format($totalIncrease, 2) ?>
                    </div>
                    <div class="stat-label">Total Change</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?= $totalChanges ?></div>
                    <div class="stat-label">Price Changes</div>
                </div>
                <div class="stat">
                    <div class="stat-value <?= $percentChange > 0 ? 'up' : ($percentChange < 0 ? 'down' : '') ?>">
                        <?= $percentChange >= 0 ? '+' : '' ?><?= number_format($percentChange, 1) ?>%
                    </div>
                    <div class="stat-label">Since First Permit</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="header">Price Change History</div>
            <div class="subtitle">Weekly permits only</div>
            <?php foreach ($priceChanges as $change): ?>
            <div class="change-item">
                <div>
                    <div class="change-date"><?= htmlspecialchars($change['date']) ?></div>
                    <a href="<?= $urlBase ?>/?permit=<?= htmlspecialchars($change['permitNumber']) ?>" class="change-permit"><?= htmlspecialchars($change['permitNumber']) ?></a>
                </div>
                <div class="change-info">
                    <div class="change-price">$<?= number_format($change['price'], 2) ?></div>
                    <?php if ($change['isFirst']): ?>
                        <div class="change-amount baseline">First permit</div>
                    <?php else: ?>
                        <div class="change-amount <?= $change['change'] > 0 ? 'up' : 'down' ?>">
                            <?= $change['change'] > 0 ? '+' : '' ?>$<?= number_format($change['change'], 2) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="empty">No price history available</div>
        </div>
        <?php endif; ?>

        <div class="links">
            <a href="<?= $urlBase ?>/" class="link">Current Permit</a>
            <a href="<?= $urlBase ?>/history/" class="link">Permit History</a>
        </div>
    </div>
    <div class="project-links">
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-buyer" target="_blank">Auto-buyer</a>
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-display" target="_blank">E-ink Display</a>
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-android" target="_blank">Android App</a>
    </div>
</body>
</html>
