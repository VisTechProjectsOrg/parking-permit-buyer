// Auto-reload switching: detects when a new deployment has happened and
// prompts the user to reload. Records the commit the page loaded with
// (from <body> dataset), polls /api/version, reloads silently if the tab
// is hidden or shows a banner if visible.
(function () {
    let loadedVersion = null;
    let bannerShown = false;

    function init() {
        loadedVersion = document.body.dataset.versionCommit;
        if (!loadedVersion) return;

        setInterval(checkVersion, 60000);
        setTimeout(checkVersion, 2000);
    }

    function checkVersion() {
        if (bannerShown) return;
        const url = (document.body.dataset.urlBase || '') + '/api/version.php';
        fetch(url, { cache: 'no-store' })
            .then(r => r.json())
            .then(data => {
                if (data.version && data.version !== loadedVersion && !bannerShown) {
                    bannerShown = true;
                    console.warn(
                        `[Version Check] New version detected: ${data.version} ` +
                        `(loaded: ${loadedVersion})`
                    );
                    if (document.hidden) {
                        location.reload();
                        return;
                    }
                    showUpdateBanner();
                }
            })
            .catch(() => { /* network blip — try again next interval */ });
    }

    function showUpdateBanner() {
        if (document.getElementById('version-update-banner')) return;
        const banner = document.createElement('div');
        banner.id = 'version-update-banner';
        banner.className = 'version-update-bar';
        banner.innerHTML =
            '<span>A new version of the site is available.</span>' +
            '<button onclick="location.reload()">Reload</button>';
        const container = document.querySelector('.container');
        (container || document.body).prepend(banner);
    }

    document.addEventListener('DOMContentLoaded', init);
})();
