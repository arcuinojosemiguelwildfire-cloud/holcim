# Event-day checklist

## Before the event
- [ ] Production URL opens over **HTTPS** (padlock shown) and you can sign in.
- [ ] `php backend/cli/check-readiness.php` → "No blocking problems found."
- [ ] Database backup taken.
- [ ] If this server was used for testing: Settings › **System Reset** (type `RESET EVENT DATA` + your password) **once, before** setting up the real event. Then recreate staff / event operator / scanner operator accounts. Never use it during or after the event.
- [ ] Events page: the correct event is **Active**.
- [ ] Events › **Days**: every event day is listed with the right date (Day 1, Day 2, …).
- [ ] Settings › **Scanner Operators**: one enabled account per scanning device/person; each one can sign in with its username.
- [ ] Attendees imported (Attendees › Import attendees). For the client's workbook, the `External Attendees` sheet is detected automatically (Name 1 = Company, Cluster = location, one attendee per Attendee column); review invalid/duplicate rows.
- [ ] QR / ID Generator: **Generate missing QR codes** → QR missing = 0 (no "Do not print" warning shown).
- [ ] Print labels (Print all, 100% / actual size). **Scan one printed label** on the Registration page.
- [ ] Registration: each USB/Bluetooth QR scanner is connected, sends Enter after the code, and a test scan of a printed label shows "Registration Successful".
- [ ] Minor and Major Randomizer: test draw, **Void draw** it, test fullscreen on the LED/projector laptop.
  (Test draws stay in history as VOID; that is expected.)

## Before each event day
- [ ] Database backup taken (end of the previous day or now).
- [ ] Events › **Days** › **Set as current** on today's day. The top bar must show **Current Event Day: Day N — today's date**.
- [ ] `php backend/cli/check-readiness.php` → "Current event day" shows today's date (no "not today's date" warning).
- [ ] Registration page shows Registered = 0 for the new day (previous days' check-ins are kept, not reset).
- [ ] Scanner operators signed in on each device; disable any account that should not be used today.
- [ ] Major: import today's form responses only after today's form closes (imports apply to the current day).

## During registration
- [ ] Open **Registration**; the **Scan Attendee QR** box must show "Ready to scan" (amber warning = click the box once).
- [ ] Scan the attendee's QR with the scanner → green "Registration Successful". No mouse needed between attendees.
- [ ] Watch Registered / Remaining (current day). Red result = invalid/other event/archived: check the attendee at the desk.
- [ ] "Already registered for Day N" = this attendee already checked in **today**; no action needed.
- [ ] Use **Attendee lookup** on the scanner page to check whether someone is registered today.
- [ ] Recent Scans: **My Scans** / **All Scans** and the result filter help find problem scans.

## Walk-ins not on the list
- [ ] Attendees › **Add attendee** (admin or event operator): Full Name required; Company, Cluster, Employee ID, Email optional. Print or download the QR from the success screen, then **scan it** at registration — adding a person does not register them or make them raffle eligible.

## During event (manual participants)
- [ ] Attendee present but could not be scanned (lost QR, etc.) and should join today's draw: Minor/Major Randomizer › **+ Add Participant** → search → reason → add. This does **not** register them.
- [ ] Check **Today's participants** (Source: Registration / Manual) before the draw.

## Minor draw
- [ ] Open **Minor Randomizer** → **Enter fullscreen** (Space/Enter draws, Esc exits).
- [ ] Draw, confirm the winner is present.
- [ ] Not present? **Void draw** (reason e.g. "Winner not present") → **Next draw**.

## Raffle eligibility (no Major QR, form or import)
- [ ] Registration is all that is needed: everyone scanned **today** is in both the Minor and the Major pool.
- [ ] A Minor winner leaves the Minor pool but stays in the Major pool (and vice versa). Voiding a draw puts the person back in that pool.
- [ ] Check the eligible counts on the Minor / Major Randomizer pages before each draw.

## If the client asks to reset the draws
- [ ] Settings › **Randomizer Reset** (admin): pick the event, the **day** and Minor / Major / Minor + Major; check the preview list; type the phrase shown and your password. Previous winners of that day + randomizer can then be drawn again. Draw history stays in Winners.xlsx (marked in "Exclusion Reset At").

## Major draw
- [ ] Open **Major Randomizer** → **Enter fullscreen**.
- [ ] Draw, confirm the winner; **Void draw** if necessary and draw again.

## After each day (do NOT delete or reset records)
- [ ] **Reports** › Excel lists: download **Day N Attendees.xlsx** and **Winners.xlsx**; CSVs ("Day N only") if needed.
- [ ] Database backup taken.
- [ ] If this server was used for testing: Settings › **System Reset** (type `RESET EVENT DATA` + your password) **once, before** setting up the real event. Then recreate staff / event operator / scanner operator accounts. Never use it during or after the event.
- [ ] Do **not** delete, archive or re-import anything to "clear" the day. The next day starts empty automatically when an admin sets it as the current day.

## After the event
- [ ] **Reports**: download **Winners.xlsx** (all days) and every **Day N Attendees.xlsx**; "All days" CSVs if needed.
- [ ] Database backup taken.
- [ ] If this server was used for testing: Settings › **System Reset** (type `RESET EVENT DATA` + your password) **once, before** setting up the real event. Then recreate staff / event operator / scanner operator accounts. Never use it during or after the event.
- [ ] Disable scanner operator accounts that are no longer needed (Settings › Scanner Operators).
