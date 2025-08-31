# Route ATC Widget for phpVMS v7

This package provides a **self-registering widget** for phpVMS v7 that displays
**online ATC stations along your SimBrief route** using live VATSIM data.

---

## Features
- Reads your **latest SimBrief flight plan** (XML API)
- Splits ATC display into 3 blocks:
  - **Departure** (ATIS, GND, TWR, APP)
  - **En-route** (CTR / FSS sectors)
  - **Arrival** (ATIS, GND, TWR, APP)
- Sector-aware detection (e.g. `EDDF_N_GND`, `LFFF_W_CTR`, `EGGX_FSS`)
- Badges show **grey (offline)** or **green (online)**
- Hover tooltips show **frequency, controller name, rating, last update**
- Configurable SimBrief ID via custom profile field.
- Caching:
  - **SimBrief OFP**: 20 minutes
  - **VATSIM data**: 20 minutes

---

## Installation
1. Copy `RouteAtcWidget.php` to:
   ```
   app/Widgets/RouteAtcWidget.php
   ```

2. Copy the Blade template to:
   ```
   resources/views/layouts:/ **template** /widgets/route_atc_widget.blade.php
   ```

3. In phpVMS admin panel, add a **custom user field**:
   - Name: `SimBrief ID`
   - Save.

4. Pilots should edit their profile and fill their **SimBrief username** in the new field.

5. Add the widget to any Blade view:
   ```blade
   @widget('App\Widgets\RouteAtcWidget')
   ```

---

## Requirements
- phpVMS v7
- PHP 8.0+
- Bootstrap 5 (for tooltips)
- Working SimBrief and VATSIM API access

---

## Credits
Developed by Rick Winkelman for phpVMS v7 pilots to enhance situational awareness by showing
expected ATC coverage along your planned route.

