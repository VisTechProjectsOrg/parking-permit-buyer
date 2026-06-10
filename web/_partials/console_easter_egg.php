<script>
    // A special message for those who look
    console.log(`%c
    ╭─────────────────────────────────────────╮
    │                                         │
    │      🖕 CITY OF TORONTO PARKING 🖕      │
    │                                         │
    │   "The only thing more expensive than   │
    │    Toronto parking is Toronto rent"     │
    │                                         │
    │   So I automated the whole thing...     │
    │                                         │
    ╰─────────────────────────────────────────╯
    `, 'color: #4caf50; font-family: monospace;');

    // Linus's middle finger rendered in the console (Chrome/Edge/Firefox).
    // Self-hosted because Chrome blocks cross-origin images in %c background-image.
    // GIF is 268x200, so padding is half each dimension and background-size is explicit.
    console.log(
        '%c+',
        'font-size: 1px; line-height: 0; color: transparent;' +
        'padding: 100px 134px;' +
        'background: url("<?= $urlBase ?>/static/linus_middle_finger.gif") no-repeat;' +
        'background-size: 268px 200px;'
    );

    console.log(`%c
If you're already here, check out my other automated bullshit:
🖕 Auto-buyer:    https://github.com/VisTechProjectsOrg/parking-permit-buyer
🖕 E-ink display: https://github.com/VisTechProjectsOrg/parking-permit-display
🖕 Android app:   https://github.com/VisTechProjectsOrg/parking-permit-android
    `, 'color: #4caf50; font-family: monospace;');
</script>
