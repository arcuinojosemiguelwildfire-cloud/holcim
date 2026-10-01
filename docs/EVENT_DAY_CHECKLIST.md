# Event-day checklist

## Before the event
- [ ] Production URL opens over **HTTPS** (padlock shown) and you can sign in.
- [ ] `php backend/cli/check-readiness.php` → "No blocking problems found."
- [ ] Database backup taken.
- [ ] Events page: the correct event is **Active**.
- [ ] Attendees imported (Attendees › Import attendees); review invalid/duplicate rows.
- [ ] QR / ID Generator: **Generate missing QR codes** → QR missing = 0 (no "Do not print" warning shown).
- [ ] Print labels (Print all, 100% / actual size). **Scan one printed label** on the Registration page.
- [ ] `MAJOR_FORM_URL` set; open **Major QR**, scan it with a phone → the client's form opens.
- [ ] Registration: camera works on each scanning device (laptop/Android), and a test scan succeeds.
- [ ] Minor and Major Randomizer: test draw, **Void draw** it, test fullscreen on the LED/projector laptop.
  (Test draws stay in history as VOID; that is expected.)

## During registration
- [ ] Open **Registration**, allow the camera.
- [ ] Scan the attendee's QR → green "Registration successful".
- [ ] Move the ID away after each scan (the same QR shows "Already registered" if held in view).
- [ ] Watch Registered / Remaining. Red result = invalid/other event/archived: check the attendee at the desk.

## Minor draw
- [ ] Open **Minor Randomizer** → **Enter fullscreen** (Space/Enter draws, Esc exits).
- [ ] Draw, confirm the winner is present.
- [ ] Not present? **Void draw** (reason e.g. "Winner not present") → **Next draw**.

## Major eligibility
- [ ] Show **Major QR** on the LED screen (fullscreen); attendees complete the client's form.
- [ ] When the form closes: export the Google Sheet (File › Download › CSV or .xlsx).
- [ ] **Major Eligibility › Import responses** → map columns → review matched / ambiguous / unmatched → import.
- [ ] Confirm the Major Eligible count (dashboard / Major Eligibility page).

## Major draw
- [ ] Open **Major Randomizer** → **Enter fullscreen**.
- [ ] Draw, confirm the winner; **Void draw** if necessary and draw again.

## After the event
- [ ] **Reports**: download Registration, Major eligibility and Draw winners CSVs.
- [ ] Database backup taken.
