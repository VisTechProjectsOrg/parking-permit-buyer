<?php
// Returns ['commit' => '<short-sha>'|null, 'branch' => '<branch>'|null].
// Reads $basePath/version.txt first (written by CI on deploy), falls back to live git.
// Expects $basePath to be set (it is, by config.php).

function getVersionInfo($basePath) {
    $commit = null;
    $branch = null;

    // 1. version.txt — written by CI on deploy. Format: "abc1234 (branch_name)"
    $versionFile = $basePath . '/version.txt';
    if (file_exists($versionFile)) {
        $content = trim(@file_get_contents($versionFile));
        if (preg_match('/^(\S+)\s+\((.+)\)$/', $content, $m)) {
            $commit = $m[1];
            $branch = $m[2];
        } elseif ($content !== '') {
            $commit = $content;
        }
    }

    // 2. Fall back to live git (local development)
    $gitDir = escapeshellarg($basePath);
    if (!$commit) {
        $out = @shell_exec("git -C $gitDir rev-parse --short HEAD 2>&1");
        if ($out && !preg_match('/fatal|error/i', $out)) $commit = trim($out);
    }
    if (!$branch) {
        $out = @shell_exec("git -C $gitDir branch --show-current 2>&1");
        if ($out && !preg_match('/fatal|error/i', $out)) $branch = trim($out);
    }

    return ['commit' => $commit ?: null, 'branch' => $branch ?: null];
}
