<?php
require_once __DIR__ . '/../config.php';

$permits = [];
if (file_exists($historyFile)) {
    $permits = json_decode(file_get_contents($historyFile), true) ?: [];
}

$totalSpent = 0;
foreach ($permits as $permit) {
    if (isset($permit['amountPaid'])) {
        $totalSpent += floatval(str_replace(['$', ','], '', $permit['amountPaid']));
    }
}
$weeksOfMyLife = count($permits);

// Latest weekly permit price (walk history backwards for the most recent ~7-day permit)
function _isWeeklyPermit($p) {
    $from = preg_replace('/:\s*\d{1,2}:\d{2}$/', '', $p['validFrom'] ?? '');
    $to = preg_replace('/:\s*\d{1,2}:\d{2}$/', '', $p['validTo'] ?? '');
    $f = DateTime::createFromFormat('M j, Y', trim($from));
    $t = DateTime::createFromFormat('M j, Y', trim($to));
    return $f && $t && $f->diff($t)->days >= 6;
}
$latestWeeklyPrice = null;
for ($i = count($permits) - 1; $i >= 0; $i--) {
    if (_isWeeklyPermit($permits[$i]) && !empty($permits[$i]['amountPaid'])) {
        $latestWeeklyPrice = floatval(str_replace(['$', ','], '', $permits[$i]['amountPaid']));
        break;
    }
}
$priceLabel = $latestWeeklyPrice ? '$' . number_format($latestWeeklyPrice, 2) : '~$50';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Why this exists</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1f2e;
            color: #e2e8f0;
            min-height: 100vh;
            padding: 16px;
            line-height: 1.5;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
        }
        h1 {
            text-align: center;
            margin-bottom: 16px;
            font-size: 22px;
            color: #fff;
        }
        .card {
            background: #2a3142;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 14px;
            color: #cbd5e1;
            font-size: 15px;
        }
        .card p { margin-bottom: 12px; }
        .card p:last-child { margin-bottom: 0; }
        .card strong { color: #fff; }
        .price-tag {
            color: #f44336;
            font-weight: 600;
        }
        .lede {
            font-size: 17px;
            color: #fff;
            font-weight: 500;
        }
        .built-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .built-list li {
            padding: 8px 0;
            padding-left: 18px;
            position: relative;
        }
        .built-list li::before {
            content: '→';
            position: absolute;
            left: 0;
            color: #64b5f6;
        }
        .built-list a {
            color: #64b5f6;
            text-decoration: none;
        }
        .built-list a:hover { text-decoration: underline; }
        .counter-card {
            text-align: center;
            background: linear-gradient(135deg, #2a3142, #232838);
            border: 1px solid #3d4659;
        }
        .counter-label {
            font-size: 12px;
            color: #8892a6;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        .counter-value {
            font-size: 36px;
            font-weight: 700;
            color: #f44336;
            margin-bottom: 4px;
        }
        .counter-sub {
            font-size: 13px;
            color: #8892a6;
        }
        .signoff {
            text-align: center;
            font-size: 48px;
            margin: 8px 0;
        }
        .signoff-text {
            text-align: center;
            color: #8892a6;
            font-size: 13px;
            font-style: italic;
            margin-bottom: 4px;
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
        <h1>Why this exists</h1>

        <div class="card">
            <p class="lede">The City of Toronto won't let me buy a yearly parking permit.</p>
            <p>They want me to come back <strong>every week</strong>, fill out the same form, type the same plate, type the same address, pay them <span class="price-tag"><?= htmlspecialchars($priceLabel) ?></span>, and get a PDF I'm supposed to print and stick in my windshield. Forever.</p>
            <p>Their website looks like it was built in 2005.</p>
            <p>Also: I'm lazy.</p>
        </div>

        <div class="card">
            <p style="margin-bottom: 12px;"><strong>So I built this:</strong></p>
            <ul class="built-list">
                <li>Automated the whole thing &mdash; <a href="https://github.com/VisTechProjectsOrg/parking-permit-buyer" target="_blank">auto-buyer</a></li>
                <li>Put the permit on an <a href="https://github.com/VisTechProjectsOrg/parking-permit-display" target="_blank">e-ink display</a> in the windshield (no more printing)</li>
                <li>Built an <a href="https://github.com/VisTechProjectsOrg/parking-permit-android" target="_blank">Android app</a> to check it on the go</li>
                <li>Built this dashboard so I can watch the money pile up</li>
            </ul>
        </div>

        <?php if ($totalSpent > 0): ?>
        <div class="card counter-card">
            <div class="counter-label">Money fed to Toronto so far</div>
            <div class="counter-value">$<?= number_format($totalSpent, 2) ?></div>
            <div class="counter-sub">across <?= $weeksOfMyLife ?> permits</div>
        </div>
        <?php endif; ?>

        <div class="signoff-text">City of Toronto:</div>
        <div class="signoff">&#x1F595;</div>
    </div>
    <div class="project-links">
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-buyer" target="_blank">Auto-buyer</a>
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-display" target="_blank">E-ink Display</a>
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-android" target="_blank">Android App</a>
    </div>
    <?php include __DIR__ . '/../_partials/bottom_nav.php'; ?>
    <?php include __DIR__ . '/../_partials/version_banner.php'; ?>
    <?php include __DIR__ . '/../_partials/console_easter_egg.php'; ?>
</body>
</html>
