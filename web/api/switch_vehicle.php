<?php
require_once __DIR__ . '/../config.php';

$plate = trim($_GET['plate'] ?? '');
$token = trim($_GET['token'] ?? '');

function render_page($title, $message, $kind = 'info') {
    $accent = ['ok' => '#2e7d32', 'err' => '#c62828', 'info' => '#1976d2'][$kind] ?? '#1976d2';
    ?><!DOCTYPE html>
    <html lang="en"><head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?></title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1a1f2e; color: #e2e8f0; min-height: 100vh; margin: 0; padding: 16px; }
            .container { max-width: 500px; margin: 80px auto 0; text-align: center; }
            .card { background: #2a3142; border-radius: 12px; padding: 32px 24px; }
            h1 { margin: 0 0 16px; font-size: 22px; color: <?= $accent ?>; }
            p { margin: 0 0 16px; font-size: 15px; color: #cbd5e1; line-height: 1.5; }
            a.back { display: inline-block; margin-top: 8px; color: #64b5f6; text-decoration: none; font-size: 14px; }
            a.back:hover { text-decoration: underline; }
        </style>
    </head><body>
        <div class="container"><div class="card">
            <h1><?= htmlspecialchars($title) ?></h1>
            <p><?= $message ?></p>
            <a class="back" href="/settings/">Go to Settings</a>
        </div></div>
    </body></html><?php
    exit;
}

// Read SETTINGS_PASS from .env (shared secret with Python)
$settingsPass = null;
if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    if (preg_match('/^SETTINGS_PASS=(.+)$/m', $envContent, $m)) {
        $settingsPass = trim($m[1]);
    }
}
if (!$settingsPass) {
    render_page('Configuration error', 'Server-side secret missing.', 'err');
}

// Load current permit (token includes the permit_number so old tokens expire)
$permitNumber = null;
if (file_exists($permitFile)) {
    $permit = json_decode(file_get_contents($permitFile), true) ?: [];
    $permitNumber = $permit['permitNumber'] ?? null;
}
if (!$permitNumber) {
    render_page('No current permit', 'No active permit found to validate the link against.', 'err');
}

// Validate inputs
if (!preg_match('/^[A-Z0-9]{1,10}$/i', $plate) || !preg_match('/^[a-f0-9]{16}$/', $token)) {
    render_page('Invalid link', 'The switch link is malformed.', 'err');
}

// Recompute the expected token and compare in constant time
$msg = 'switch:' . $permitNumber . ':' . strtoupper($plate);
$expected = substr(hash_hmac('sha256', $msg, $settingsPass), 0, 16);
if (!hash_equals($expected, strtolower($token))) {
    render_page('Link expired or invalid', 'This switch link is no longer valid (a new permit may have already been purchased).', 'err');
}

// Verify the plate exists in info_cars.json
$cars = file_exists($carsFile) ? (json_decode(file_get_contents($carsFile), true) ?: []) : [];
$matched = null;
foreach ($cars as $c) {
    if (strcasecmp(($c['plate'] ?? ''), $plate) === 0) { $matched = $c; break; }
}
if (!$matched) {
    render_page('Unknown vehicle', 'That plate isn\'t in the vehicle list.', 'err');
}

// Update settings.json: set default_vehicle to the (canonical) plate
$settings = file_exists($settingsFile) ? (json_decode(file_get_contents($settingsFile), true) ?: []) : [];
$settings['autobuyer'] = $settings['autobuyer'] ?? [];
$currentDefault = $settings['autobuyer']['default_vehicle'] ?? null;
$newPlate = $matched['plate'];

if ($currentDefault && strcasecmp($currentDefault, $newPlate) === 0) {
    render_page('Already set', 'Default vehicle is already <strong>' . htmlspecialchars($matched['name'] . ' (' . $newPlate . ')') . '</strong>. The next auto-buy will use it.', 'ok');
}

$settings['autobuyer']['default_vehicle'] = $newPlate;
if (!file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT))) {
    render_page('Save failed', 'Could not write settings.json on the server.', 'err');
}

render_page('Default vehicle updated', 'Auto-buyer is now set to <strong>' . htmlspecialchars($matched['name'] . ' (' . $newPlate . ')') . '</strong> for the next purchase.', 'ok');
