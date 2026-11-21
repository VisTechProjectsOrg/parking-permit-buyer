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

echo json_encode($permit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
