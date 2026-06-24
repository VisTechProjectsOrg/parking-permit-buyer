# Notes / Future Ideas

## Permit handoff on expiry day (background)
On a permit's last valid day the autobuyer buys the next permit, which starts the next day.
Two problems were addressed:

1. **Coverage gap (fixed):** the buy used to wait until the permit had fully expired, so the
   new permit (which starts "tomorrow") began a day late, leaving one uncovered day. The buy
   now fires on the permit's last valid day (`vehicle_covered_tomorrow`), giving contiguous
   coverage.
2. **Display flip (workaround in place):** the e-ink shows whichever permit is in `permit.json`
   when it boots (it only powers on in the car). On the last valid day the display switches
   from the expiring permit to the next one at 4 PM (`DISPLAY_FLIP_HOUR`), so the evening drive
   renders the permit that's valid overnight. Server-side timing is a workaround for the
   display being dumb and unpowered — it needs a cron run at/after 4 PM (a daily afternoon buy
   run, or `--refresh-display`) to push the flip.

## Proper fix: micro-LiPo on the e-ink display  (display repo, later)
Add a small battery + buffer to the e-ink so it can:
- cache the next permit in flash as soon as it's available, and
- flip the displayed permit at **exactly midnight** when the new one legally becomes valid,
  instead of relying on a server-side ~4 PM approximation.

This moves the timing decision onto the device (the only thing with perfect timing info) and
removes the dependency on when a server cron runs / when the car is driven. Once this lands,
the server-side `DISPLAY_FLIP_HOUR` flip logic can be retired.
Lives in: https://github.com/VisTechProjectsOrg/parking-permit-display
