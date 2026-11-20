<?php
// Which permit should be on the display right now. Shared by the main page and
// api/permit.php (the feed the Android app syncs to the e-ink display).

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

// The permit that should display right now for the plate in permit.json: the active one,
// or the next (already-bought) one from 4 PM on the last valid day. Mirrors the Python
// display logic so it is right even if permit.json is briefly stale between cron runs.
function currentDisplayPermit($permitFile, $historyFile) {
    if (!file_exists($permitFile)) return null;
    $permit = json_decode(file_get_contents($permitFile), true);
    if (!$permit) return null;
    if (file_exists($historyFile)) {
        $history = json_decode(file_get_contents($historyFile), true) ?: [];
        $plate = strtoupper($permit['plateNumber'] ?? '');
        $forPlate = array_values(array_filter($history, fn($p) => strtoupper($p['plateNumber'] ?? '') === $plate));
        $selected = $plate ? selectDisplayPermit($forPlate, new DateTime()) : null;
        if ($selected) $permit = $selected;
    }
    return $permit;
}
