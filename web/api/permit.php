<?php
// Permit the e-ink display should show right now. The Android app fetches this and
// pushes it to the display over BLE.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../permit_select.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$permit = currentDisplayPermit($permitFile, $historyFile);
if (!$permit) {
    http_response_code(404);
    echo json_encode(['error' => 'No permit']);
    exit;
}

// Last day this plate is covered by any bought permit, so the app can flag a missed purchase
$permit['coveredThrough'] = $permit['validTo'] ?? '';
$latest = parsePermitDt($permit['coveredThrough']);
$history = file_exists($historyFile) ? (json_decode(file_get_contents($historyFile), true) ?: []) : [];
$plate = strtoupper($permit['plateNumber'] ?? '');
$today = (new DateTime())->format('Y-m-d');
$other = null;  // the other still-relevant permit: the next one, or the expiring one after the 4 PM flip
$otherFrom = null;
foreach ($history as $p) {
    if (strtoupper($p['plateNumber'] ?? '') !== $plate) continue;
    $vf = parsePermitDt($p['validFrom'] ?? '');
    $vt = parsePermitDt($p['validTo'] ?? '');
    if ($vt && (!$latest || $vt > $latest)) {
        $latest = $vt;
        $permit['coveredThrough'] = $p['validTo'];
    }
    // Earliest not-yet-ended permit other than the main one
    if ($vf && $vt && $vt->format('Y-m-d') >= $today && ($p['permitNumber'] ?? '') !== ($permit['permitNumber'] ?? '')
            && (!$otherFrom || $vf < $otherFrom)) {
        $other = $p;
        $otherFrom = $vf;
    }
}

// The battery display caches this and switches to it on its own at validFrom. Full record so
// the app can show its price and vehicle; the app sends the display only what it draws.
if ($other) {
    $permit['next'] = $other;
}

// When the autobuyer will buy the next permit: its daily run (BUYER_RUN_HOUR) on the last covered
// day. A pause with a re-enable date (disabled_until, "skip while away") moves the buy to that day,
// and the next permit then starts the day after it: resumesOn, so the app knows the gap is planned.
$settings = file_exists($settingsFile) ? (json_decode(file_get_contents($settingsFile), true) ?: []) : [];
$ab = $settings['autobuyer'] ?? [];
$lastDay = parsePermitDt($permit['coveredThrough']);
$enabled = $ab['enabled'] ?? true;
$until = (!$enabled && !empty($ab['disabled_until']))
    ? DateTime::createFromFormat('!Y-m-d', substr($ab['disabled_until'], 0, 10)) : null;
$permit['autobuyer'] = (bool)$enabled;
if ($lastDay && ($enabled || $until)) {
    $paused = $until && $until->format('Y-m-d') > $lastDay->format('Y-m-d');
    $buyDay = $paused ? $until : $lastDay;
    $permit['nextBuy'] = $buyDay->format('M d, Y') . ': ' . sprintf('%02d:00', BUYER_RUN_HOUR);
    if ($paused) {
        $permit['resumesOn'] = (clone $until)->modify('+1 day')->format('M d, Y');
    }
}

echo json_encode($permit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
