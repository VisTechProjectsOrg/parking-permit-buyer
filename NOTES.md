# Notes / Future Ideas

## Permit handoff on expiry day (background)
On a permit's last valid day the autobuyer buys the next permit, which starts the next day.
Two problems were addressed:

1. **Coverage gap (fixed):** the buy used to wait until the permit had fully expired, so the
   new permit (which starts "tomorrow") began a day late, leaving one uncovered day. The buy
   now fires on the permit's last valid day (`vehicle_covered_tomorrow`), giving contiguous
   coverage.
2. **Display flip (server-side workaround in place):** on the last valid day the displayed
   permit switches from the expiring one to the next at 4 PM (`DISPLAY_FLIP_HOUR`). Needs a
   cron run at/after 4 PM (afternoon buy run, or `--refresh-display`) to push the change.
   This is a stopgap for the dumb/unpowered e-ink; the proper on-device midnight flip is
   tracked in the display repo and will retire this once it lands.

### Planned: advance purchase (for the cable-free display)
To give the battery display a multi-day window to sync the next permit over BLE, the buyer will
buy **~2-3 days early** (configurable lead time) instead of on expiry day, with **start date =
current permit's expiry + 1 day** (currently hardcoded to "tomorrow"). Confirmed the Toronto site
accepts a future start date (verified 2026-06-25: bought Valid-From Jul 02 on Jun 25). Full design
in the display repo's NOTES.md ("Set-and-forget sync design").

## TODO (web / buyer app)
- [ ] Default vehicle management: **add/remove vehicles** in the settings UI (not just switch which
      is default). Edits `config/info_cars.json` (name + plate). Today vehicles are hand-edited in
      the JSON on the server; want add/remove from the web UI, with validation (plate format, no dupes).

## Display firmware + battery work (moved)
All the e-ink display notes — BLE-only sync, the 200mAh LiPo + deep-sleep plan, VME290 power
gating, battery-life estimates, charging/BMS findings, and sleep-current testing — now live in
the **display repo**: `parking_pass_display` → `NOTES.md` (dev branch), alongside the
`sleep_test.cpp` test sketch.

Repo: https://github.com/VisTechProjectsOrg/parking-permit-display
