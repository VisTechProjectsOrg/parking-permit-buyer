<?php
// Auto-detect local vs production environment
$isLocal = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false)
        || (strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);

if ($isLocal) {
    // Local development paths (relative to web folder)
    $basePath = dirname(__DIR__);
    $permitFile = $basePath . '/permit.json';
    $historyFile = $basePath . '/permits_history.json';
    $settingsFile = $basePath . '/config/settings.json';
    $carsFile = $basePath . '/config/info_cars.json';
    $envFile = $basePath . '/.env';
    $urlBase = '';  // Local: no prefix
} else {
    // Production server paths
    $basePath = '/home/admin/Toronto-Parking-Pass-Buyer';
    $permitFile = $basePath . '/permit.json';
    $historyFile = $basePath . '/permits_history.json';
    $settingsFile = $basePath . '/config/settings.json';
    $carsFile = $basePath . '/config/info_cars.json';
    $envFile = $basePath . '/.env';
    $urlBase = '';
}
