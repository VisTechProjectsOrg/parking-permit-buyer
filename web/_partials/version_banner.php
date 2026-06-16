<?php
// Version banner + auto-reload partial. Include once near </body> on each page.
// Expects $basePath and $urlBase from config.php (page is responsible for the require).
require_once __DIR__ . '/../version_info.php';
$_ver = getVersionInfo($basePath);
$_commit = $_ver['commit'];
$_branch = $_ver['branch'];
?>
<?php if ($_commit && $_branch && $_branch !== 'main'): ?>
<style>
    body { padding-top: 32px !important; }
    .version-bar {
        position: fixed; top: 0; left: 0; right: 0;
        text-align: center;
        font-size: 10px;
        color: #5a6378;
        padding: 2px 0;
        background: #14182380;
        border-bottom: 1px solid #2a3142;
        z-index: 200;
        font-family: monospace;
    }
    /* Push any top-pinned floating UI below the version bar */
    .logout-floating { top: 36px !important; }
    .version-update-bar { top: 36px !important; }
</style>
<div class="version-bar"><?= htmlspecialchars($_commit) ?> (<?= htmlspecialchars($_branch) ?>)</div>
<?php endif; ?>

<style>
    .version-update-bar {
        position: fixed;
        top: 16px;
        left: 16px;
        right: 16px;
        background: #1e40af;
        color: #fff;
        padding: 10px 16px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        font-size: 13px;
        z-index: 150;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    }
    .version-update-bar button {
        background: #fff;
        color: #1e40af;
        border: none;
        padding: 5px 14px;
        border-radius: 4px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
    }
</style>

<?php if ($_commit && $_branch): ?>
<script>
    document.body.dataset.versionCommit = '<?= htmlspecialchars($_commit, ENT_QUOTES) ?>';
    document.body.dataset.versionBranch = '<?= htmlspecialchars($_branch, ENT_QUOTES) ?>';
    document.body.dataset.urlBase = '<?= htmlspecialchars($urlBase, ENT_QUOTES) ?>';
    console.log(
        '%c Parking %c <?= htmlspecialchars($_commit, ENT_QUOTES) ?> %c <?= htmlspecialchars($_branch, ENT_QUOTES) ?> ',
        'background:#10b981; color:white; padding:2px 6px; border-radius:3px 0 0 3px;',
        'background:#6b7280; color:white; padding:2px 6px;',
        'background:<?= $_branch === 'dev' ? '#f97316' : ($_branch === 'staging' ? '#3b82f6' : '#8b5cf6') ?>; color:white; padding:2px 6px; border-radius:0 3px 3px 0;'
    );
</script>
<?php endif; ?>
<script src="<?= $urlBase ?>/static/version-check.js?v=<?= htmlspecialchars($_commit ?? 'dev', ENT_QUOTES) ?>"></script>
