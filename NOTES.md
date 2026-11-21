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

**Next session checklist (decided 2026-11-21, do this before Buy now):**
- Why first: today the display only gets the new permit if the phone is near the car on the
  permit's last day (7 AM-9 PM, every 30 min). A few days' lead turns that into any normal
  check-in over several days, and makes after-midnight emergencies rare.
- Setting `autobuyer.lead_days` (0-5, 0 = today's behaviour) with a selector on the settings page.
- Buy trigger: not covered within `lead_days` days (today: `vehicle_covered_tomorrow`).
- Start date: current coverage end + 1 (today: hardcoded tomorrow, `get_parking_pass` line ~1617).
- `/api/permit` `nextBuy`: last covered day minus `lead_days`, at `BUYER_RUN_HOUR` (web/config.php).
- Skip while away (below) assumes the buy runs the night before the start. Rework so a pause to
  start S buys at the run `lead_days` before S, and the date picker's earliest S follows.
- App heads-up fires 12h before `nextBuy`, so it moves earlier on its own. Check its wording
  ("auto-buys tonight") still reads right.
- Example to keep working: permit ends Friday, away for the weekend, skip so it starts Monday.
- Test with the buyer's dry-run mode first, then one real buy to confirm end to end. Real money.

### Skip while away: "next permit starts on" (done 2026-11-21)
Goal: manage it from the phone. When away for a few days, pause the autobuyer and pick the date
the next permit should start, instead of a re-enable date that's off by one.
- The buyer runs once a day at 2 AM and buys a permit starting tomorrow. So a permit starting S
  is bought at the run on S-1: the settings page stores `disabled_until = S-1` (buyer unchanged).
- Earliest S: tomorrow before 2 AM (tonight's run can still buy), else the day after tomorrow.
  Enforced in the date picker and on save.
- `/api/permit` reports a pause: `resumesOn` (= S) and `nextBuy` = S-1 at 02:00, so the app shows
  "Auto-buy <date>" instead of "Not bought", and skips the "not covered" alerts until S.
- App: optional heads-up the evening before a buy ("auto-buys tonight at 2 AM") with a
  "Skip or pause" button that opens this settings page in the app. Switch in Notification Settings.
- Later: past-midnight "need a permit today" needs a Buy now button that doesn't exist yet (the old
  `feature/buy-now` branch is merged but holds no Buy now code). It would start the buyer in the
  background with `--start-today` after midnight, behind a lock so it can't double-buy with the
  2 AM cron; test with the buyer's dry-run mode first. Buying several days ahead (above) also
  needs reworking "next permit starts on", which assumes the buy runs the night before the start.

## TODO (web / buyer app)
- [x] Rotated the leaked GitHub token (old token printed to the log by the buyer's push; rotated 2026-07-09).
- [ ] Fix the buyer's token-push path: it builds a broken token URL ("Bad hostname") and rewrites the
      display repo's `origin` to it (that's what leaked the old token). Push via the clean origin /
      credential helper instead of embedding the token, so it can't leak or corrupt the remote.
- [ ] Default vehicle management: **add/remove vehicles** in the settings UI (not just switch which
      is default). Edits `config/info_cars.json` (name + plate). Today vehicles are hand-edited in
      the JSON on the server; want add/remove from the web UI, with validation (plate format, no dupes).

## Display firmware + battery work (moved)
All the e-ink display notes — BLE-only sync, the 200mAh LiPo + deep-sleep plan, VME290 power
gating, battery-life estimates, charging/BMS findings, and sleep-current testing — now live in
the **display repo**: `parking_pass_display` → `NOTES.md` (dev branch), alongside the
`sleep_test.cpp` test sketch.

Repo: https://github.com/VisTechProjectsOrg/parking-permit-display
