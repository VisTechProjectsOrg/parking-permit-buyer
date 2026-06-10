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
    // The space-with-%c trick: padding creates the canvas, background-image
    // is the GIF, font-size:0 hides the actual space character.
    console.log(
        '%c ',
        'font-size: 0; line-height: 180px;' +
        'padding: 90px 110px;' +
        'background: url("https://media.giphy.com/media/xndHaRIcvge5y/giphy.gif") center / contain no-repeat;'
    );

    console.log(`%c
If you're already here, check out my other automated bullshit:
🖕 Auto-buyer:    https://github.com/VisTechProjectsOrg/parking-permit-buyer
🖕 E-ink display: https://github.com/VisTechProjectsOrg/parking-permit-display
🖕 Android app:   https://github.com/VisTechProjectsOrg/parking-permit-android
    `, 'color: #4caf50; font-family: monospace;');
</script>
