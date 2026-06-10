<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../version_info.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$v = getVersionInfo($basePath);
echo json_encode(['version' => $v['commit']]);
