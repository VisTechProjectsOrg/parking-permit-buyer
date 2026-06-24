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

## Display architecture (how it actually works)
- Board: **Heltec Vision Master E290** (ESP32-S3 + 2.9" e-ink).
- Transport: **BLE only.** The Android app (ParkingPermitSync) pulls permit.json and pushes
  it to the display over Bluetooth. **No WiFi on the device.**
- The e-ink holds its image with zero power.
- **Current state:** the firmware never sleeps (`loop()` polls the button, BLE server stays
  up). In practice the display is **manually plugged into USB ~once a week** to sync the new
  permit, then unplugged — the e-ink keeps showing it. No battery in use yet.

## Future: 200mAh LiPo + deep sleep  (display repo: parking_pass_display)
Goal: **run for months on battery without ever plugging it in**, and auto-flip from the
expiring permit to the next one at **exactly midnight**. Moves the timing decision onto the
device instead of the server-side ~4 PM approximation; once it lands, the `DISPLAY_FLIP_HOUR`
flip can be retired.

**Prerequisite: the battery is useless until the firmware deep-sleeps.** As-is (always on,
~30-60mA) a 200mAh cell lasts only ~3-6 h. BLE is cheap (~0.02mAh per sync), so the months
target is entirely about getting deep-sleep current low.

**Feasibility for "months" on 200mAh** (battery life ~= 200mAh / avg current):
- ~50µA  -> ~5+ months  (needs Vext/EPD/LED gated; clean board)
- ~100µA -> ~2.5 months
- ~200µA -> ~5-6 weeks
- ~1mA   -> ~1 week

So months is achievable, but 200mAh is tight — it only works if sleep current is ~<=100µA.
Heltec dev boards often leak (USB-serial chip, charge IC quiescent, LED) and may sit at
hundreds of µA / ~1mA unless gated or hardware-trimmed. **Measure first.** If it can't get
under ~100µA, either trim the hardware or use a bigger cell (500-1000mAh) for comfortable margin.

Wake strategy:
- Deep sleep by default.
- **Button (pin 21)** -> ext/GPIO wake -> BLE sync with phone -> sleep.
- **RTC timer wake at midnight** on expiry day -> redraw the cached next permit -> sleep (no radio).
- **Last 1-2 days:** short periodic advertise windows so the phone can push the freshly-bought
  permit without a button press; cache it.
- BLE caveat: while asleep it isn't advertising, so the phone can only sync during a wake
  window or button press.

Battery life is dominated by deep-sleep current (**measure it** — Heltec needs `Vext` + EPD
rail + LED gated off): ~50µA -> weeks-to-months; ~200µA -> ~5-6 weeks; ~1mA -> ~1 week.

### Power source aware (USB vs battery)
Detect power source at boot (read the charge IC VBUS/PG pin, or an ADC on the USB rail) and branch:
- **On USB:** stay awake, BLE server up, charging — sync freely. This is the current weekly
  "plug in to update" behaviour, unchanged. Power doesn't matter while plugged in.
- **On battery:** deep sleep aggressively; wake only on button or the RTC midnight flip.

So it acts exactly like today when plugged in, and only enters the months-long sleep regime on the cell.

### Getting deep-sleep current down (power gating)
`sleep current = ESP32-S3 chip (~10µA) + every ungated peripheral`. To reach ~50µA you must kill
the mA-level offenders before `esp_deep_sleep_start()`:
- **Vext** — Heltec's software-controlled rail; the e-ink panel + boost circuit sit on it. Cut it
  via the Vext control GPIO before sleep. Safe: e-ink is bistable and holds its image with no power.
- **USB-serial chip** (CP210x/CH340) — draws ~0.5-5mA continuously if fed from the battery/3V3 rail.
  Confirm it's VBUS-powered (dead on battery) or remove it (the S3 has native USB). Hardware check/mod.
- **LED** (power/charge LED on the battery rail) — constant ~1-5mA. Remove the LED/resistor, or turn
  it off via GPIO before sleep.
- Remaining floor: 3V3 LDO + charge-IC quiescent current (usually tens of µA; needs a hardware swap to change).
Only a meter shows which actually bite -- measure, then gate the worst.

### TODO (firmware)
- [ ] Measure deep-sleep current on the E290 (battery line, asleep) for a real runtime number.
- [ ] Power-source detect (VBUS/PG pin): USB -> stay awake + charge; battery -> deep sleep.
- [ ] Gate before sleep: cut Vext (EPD rail), kill LED, verify USB chip is VBUS-only (else remove).
- [ ] Store `validTo` + "next permit cached" flag in RTC memory (survives deep sleep).
- [ ] Wake-source dispatch: button -> BLE sync; timer -> midnight flip / advertise window.
- [ ] Compute sleep duration from `validTo` (long normally; precise wake at midnight on expiry day).
- [ ] Last-day periodic advertise windows to auto-receive the new permit over BLE.
- [ ] Cache next permit in flash; midnight redraw from cache (no BLE needed).
- [ ] Confirm graceful failure: if the battery dies, the e-ink just holds the last image.

Repo: https://github.com/VisTechProjectsOrg/parking-permit-display
