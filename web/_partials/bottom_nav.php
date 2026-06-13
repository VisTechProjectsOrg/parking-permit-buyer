<?php
// Sticky-bottom nav, included near </body> on each page.
// Detects active page from script directory; expects $urlBase from config.php.
$_navDir = basename(dirname($_SERVER['SCRIPT_NAME']));
$_navPage = in_array($_navDir, ['history', 'prices', 'settings', 'why'], true) ? $_navDir : 'current';
?>
<style>
    body { padding-bottom: 84px; }
    .bottom-nav {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: #1e2433;
        border-top: 1px solid #2a3142;
        display: grid;
        grid-auto-columns: 1fr;
        grid-auto-flow: column;
        gap: 14px;
        padding: 14px 24px calc(14px + env(safe-area-inset-bottom, 0px));
        z-index: 100;
    }
    .bottom-nav a {
        text-align: center;
        color: #8892a6;
        text-decoration: none;
        font-size: 13px;
        padding: 10px 6px;
        border-radius: 8px;
        transition: color 0.15s, background 0.15s;
    }
    .bottom-nav a:hover { color: #e2e8f0; }
    .bottom-nav a.active {
        color: #64b5f6;
        font-weight: 600;
        background: #2a3142;
    }
</style>
<nav class="bottom-nav">
    <a href="<?= $urlBase ?>/" class="<?= $_navPage === 'current' ? 'active' : '' ?>">Current</a>
    <a href="<?= $urlBase ?>/history/" class="<?= $_navPage === 'history' ? 'active' : '' ?>">History</a>
    <a href="<?= $urlBase ?>/prices/" class="<?= $_navPage === 'prices' ? 'active' : '' ?>">Prices</a>
    <a href="<?= $urlBase ?>/why/" class="<?= $_navPage === 'why' ? 'active' : '' ?>">Why</a>
    <a href="<?= $urlBase ?>/settings/" class="<?= $_navPage === 'settings' ? 'active' : '' ?>">Settings</a>
</nav>
