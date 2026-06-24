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

### VME290 pin map (from heltec-eink-modules `Platforms/VisionMasterE290`)
- Vext (peripheral power): **GPIO18, active HIGH**. Lib has `Platform::VExtOff()` / `VExtOn()`.
- LED (white): **GPIO45** (`LED_BUILTIN`). Button: **GPIO21** (+ BOOT GPIO0).
- Display: CS=3, DC=4, RST=5, BUSY=6, SDI/MOSI=1, CLK=2.
- LoRa SX1262: NSS=8, SCK=9, MOSI=10, MISO=11, NRST=12, BUSY=13, DIO1=14.

### Getting deep-sleep current down (power gating) -- per HT-VME290 schematic + datasheets
`sleep current = ESP32-S3 chip (~10µA) + every ungated peripheral`. Offenders, biggest first:
- **LoRa SX1262 (HT-RA62, U8)** -- THE one that matters. Core is on the always-on `VDD_3V3` rail,
  so Vext does NOT kill it; un-slept it draws ~0.6-1.5mA (~1 week on 200mAh alone). Code already
  exists in the lib: `Platforms/WirelessPaper/power_controls.cpp` does a software-SPI `SetSleep`
  (0x84, 0x04) then sets LoRa pins high-Z. The VME290 platform lists the LoRa pins "only used for
  prepareToSleep()" but does NOT implement it -- port that ~12-line routine to the E290 pins
  (NSS=8/SCK=9/MOSI=10). Drops it to ~1µA. No rail cut / hardware mod needed.
- **Vext (GPIO18)** -- cuts the e-ink panel + its HV boost (L5/D4-D6) and the LoRa RF front-end.
  Leave OFF for sleep via `VExtOff()`. Safe: e-ink is bistable, holds its image with no power.
- **LED (GPIO45)** -- drive off before sleep. (LED1 orange is charge-status off VDD_5V, only lit
  while charging on USB -- ignore.)
- **Battery ADC divider already gated**: R13 390k / R15 100k (ratio 0.204, VBAT_Read = VBAT*0.204)
  switched by Q2 via `ADC_Ctrl` -- only draws while measuring. Leave disabled in sleep.
- **NO USB-UART bridge** -- native S3 USB (`ARDUINO_USB_MODE=1`). Nothing to do.
- **LDOs are NOT the floor**: CE6260 Iq = **6µA typ** (datasheet); the always-on VDD_3V3 LDO costs
  ~6µA, the switched Ve_3V3 LDO ~0.1µA when off. Charge IC (LGS4056H) adds a few µA.

So floor ~= S3 deep sleep (~10µA) + LDO (6µA) + charge IC (~few µA) + LoRa slept (~1µA) ~= **~20-40µA
=> roughly 5-12 months on 200mAh.** LoRa is the whole ballgame; everything else is small. Measure to confirm.

### Battery read (custom -- not in the display lib)
heltec-eink-modules has no battery read. Divider is known (0.204); it's gated by `ADC_Ctrl`/Q2.
Missing: the GPIO numbers for `VBAT_Read` (ADC) and `ADC_Ctrl` -- pull from Heltec's own VisionMaster
board file/docs. Then: assert ADC_Ctrl -> analogRead -> divide by 0.204 -> de-assert.

### USB-vs-battery detection
No dedicated VBUS-sense GPIO obvious in the schematic. Options: read the charge IC (LGS4056H)
`CHRG`/`DONE` pins, or sample `VBAT_Read` (~4.2V + charging => on USB). Check Heltec's board file first.

### TODO (firmware)
- [ ] Sleep the LoRa SX1262 -- the dominant drain; do first. Port the SetSleep (0x84,0x04) routine
      from `Platforms/WirelessPaper/power_controls.cpp` to the E290 LoRa pins (NSS=8/SCK=9/MOSI=10).
- [ ] Measure deep-sleep current on the E290 (battery line, asleep) for a real runtime number.
- [ ] Gate before sleep: `VExtOff()` (GPIO18), turn LED off (GPIO45), leave ADC divider gated.
- [ ] Find `VBAT_Read` + `ADC_Ctrl` GPIOs from Heltec's VisionMaster board file/docs.
- [ ] Battery read: assert ADC_Ctrl -> analogRead -> / 0.204 -> de-assert (divider 390k/100k).
- [ ] Power-source detect (charge-IC CHRG/DONE or VBAT_Read): USB -> stay awake + charge; battery -> deep sleep.
- [ ] Store `validTo` + "next permit cached" flag in RTC memory (survives deep sleep).
- [ ] Wake-source dispatch: button -> BLE sync; timer -> midnight flip / advertise window.
- [ ] Compute sleep duration from `validTo` (long normally; precise wake at midnight on expiry day).
- [ ] Last-day periodic advertise windows to auto-receive the new permit over BLE.
- [ ] Cache next permit in flash; midnight redraw from cache (no BLE needed).
- [ ] Confirm graceful failure: if the battery dies, the e-ink just holds the last image.

Repo: https://github.com/VisTechProjectsOrg/parking-permit-display
