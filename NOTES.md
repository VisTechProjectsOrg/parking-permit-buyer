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

After checking the schematic + datasheets the realistic floor is **~20-40µA** (the LDO is only
6µA, and there's no USB-UART chip to leak) -- justification in the gating section, runtime table
in "Battery life estimate" below. Months is comfortably achievable on either a 100mAh or 200mAh
cell once the LoRa is slept. **Still measure the real sleep current to confirm before trusting it.**

Wake strategy:
- Deep sleep by default.
- **Button (pin 21)** -> ext/GPIO wake -> BLE sync with phone -> sleep.
- **RTC timer wake at midnight** on expiry day -> redraw the cached next permit -> sleep (no radio).
- **Last 1-2 days:** short periodic advertise windows so the phone can push the freshly-bought
  permit without a button press; cache it.
- BLE caveat: while asleep it isn't advertising, so the phone can only sync during a wake
  window or button press.

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

So floor ~= S3 deep sleep (~10µA) + LDO (6µA) + charge IC (~few µA) + LoRa slept (~1µA) ~= **~20-40µA**.
LoRa is the whole ballgame; everything else is small. Measure to confirm.

### Battery life estimate (deep-sleep design; wakes are negligible => runtime ~= capacity / floor)
| Sleep floor | 100mAh | 200mAh |
|-------------|--------|--------|
| ~20µA (best, clean board) | ~6-7 months | ~12-14 months |
| ~30µA (realistic)         | ~4-5 months | ~8-9 months   |
| ~40µA (conservative)      | ~3-3.5 months | ~6-7 months |
| **LoRa NOT slept (~1mA)** | **~4 days** | **~8 days** |

Practical takeaway: **100mAh ~= 3-6 months, 200mAh ~= 6-12 months** unplugged. Caveats: usable
capacity is ~80-85% of rating, and at multi-month timescales LiPo self-discharge (~2-3%/month)
eats in -- so treat the top figures as theoretical (ceiling ~a year, comfortably several months).
And it recharges over USB on every drive, so it only ever has to bridge days/weeks between charges.
The cell size isn't the deciding factor -- sleeping the LoRa is.

### Charging + battery protection (from schematic)
- **Charges the LiPo: YES.** U6 **LGS4056H** is a TP4056-class single-cell charger (CC/CV +
  termination), fed from USB `VDD_5V`. Orange LED1 = charge status. TEMP pin for a thermistor.
  Charge current is set by the PROG resistor -- **verify it vs a small cell**: these boards are
  often set for ~0.5-1A, but a 200mAh cell wants <=~0.5-1C (~100-200mA), so a high PROG current
  could over-stress it. Check before relying on it.
- **Full BMS / protection: NO dedicated IC on the board.** There's the charger + a USB/battery
  power-path mux (Q1/Q4/Q3/D2) + input fuse (F1), but no DW01-style over-discharge / over-current
  / short protection. That protection, if any, lives on the **battery's own little PCB** -- so use
  a **protected** 200mAh cell, or add firmware low-voltage cutoff. Matters for months-long use:
  with no protection, a fully drained cell can over-discharge and be damaged.
- **Firmware safeguard:** read VBAT (the ADC below) and stop waking / show "low battery" before
  the cell drops to ~3.0V. Cheap insurance against deep-discharge when left unplugged a long time.

### Battery read (custom -- not in the display lib)
heltec-eink-modules has no battery read. Divider is known (0.204); it's gated by `ADC_Ctrl`/Q2.
Missing: the GPIO numbers for `VBAT_Read` (ADC) and `ADC_Ctrl` -- pull from Heltec's own VisionMaster
board file/docs. Then: assert ADC_Ctrl -> analogRead -> divide by 0.204 -> de-assert.

### USB-vs-battery detection
No dedicated VBUS-sense GPIO obvious in the schematic. Options: read the charge IC (LGS4056H)
`CHRG`/`DONE` pins, or sample `VBAT_Read` (~4.2V + charging => on USB). Check Heltec's board file first.

### Testing the sleep current (battery in hand)
The current firmware never sleeps, so it can't be used to test battery life -- it'll draw
~30-60mA and drain a 200mAh cell in hours. Real testing needs the minimal sleep slice flashed
first (LoRa sleep + VExtOff + LED off + `esp_deep_sleep` with button/timer wake).

Measuring:
- Put a meter in series with the **battery** line (JP1), not USB.
- During deep sleep, read on the **µA range** -- expect ~20-40µA if gated right.
- During a wake (BLE sync / e-ink refresh) it spikes to tens of mA -- switch to mA range or the
  µA fuse may trip. Those wakes are brief and don't dominate the average.
- Better than a DMM if available: a power profiler (Nordic PPK2 / uCurrent) to see wake spikes too.
- Sanity checks that need no special gear: confirms it charges on USB (LED1 orange), runs on
  battery, e-ink shows the permit, button sync works on battery.

Note: flashing + measuring is done on the hardware by hand; the code slice is the only part to write.

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
- [ ] Low-voltage cutoff: stop waking / show "low battery" before VBAT ~3.0V (no hardware BMS).
- [ ] Verify charge current (PROG resistor) is safe for a 200mAh cell; use a protected cell.
- [ ] Confirm graceful failure: if the battery dies, the e-ink just holds the last image.

Repo: https://github.com/VisTechProjectsOrg/parking-permit-display
