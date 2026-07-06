#### A. Smart Default Triage (The "Gatekeeper" Question)
Before showing any time slots, display two primary radio choices or large toggle buttons:
1.  **Full Availability:** *"I can do the whole event (All days & nights)."*
    * *Action:* Keeps the detailed grid hidden, auto-checks all sub-slots behind the scenes, and lets the user move on immediately.
2.  **Partial Availability:** *"I need to pick specific days or times."*
    * *Action:* Smoothly expands the **Visual Shift Builder Matrix** below via a CSS transition animation.

#### B. Visual Shift Builder Matrix (Expanded View)
* **Layout:**
    * Columns represent chronological days (e.g., **Friday 14th**, **Saturday 15th**, **Sunday 16th**).
    * Rows represent shifts: **Daytime** and **Overnight**.
    * *UX Rule for Overnights:* To prevent calendar confusion, "Overnight" is anchored to the day it *begins*. For a 3-day event (Fri/Sat/Sun), there are 3 Daytime slots (Fri, Sat, Sun) and 2 Overnight slots (Fri Night, Sat Night). The Sunday column will visually omit the overnight row.
* **Block Elements (Tap Targets):**
    * Shifts are rendered as large, touch-friendly rounded rectangles (`min-height: 48px`).
    * **State Styling:**
        * *Available (Checked):* Colored background (e.g., desaturated green/blue), checkmark icon, bold text label.
        * *Unavailable (Unchecked):* Light grey/off-white background, subtle grey border, standard text label.
* **Bulk Toggles (Micro-Accelerators):**
    * Provide two small toggle links/buttons right above the columns: `Select All Daytime` and `Select All Overnights`.
    * *Why:* Allows a user who can do all days but zero nights to configure the entire event matrix with exactly two taps.

#### C. Cross-Event Utilities (The "Copy Configuration" Feature)
* **Component:** A button or checkbox at the footer of Event #2 onwards stating: `[ ] Copy my availability from the previous event`.
* **Interaction Logic:** When checked, the system inspects the matrix state of the preceding event. If the day counts match, it duplicates the Daytime/Overnight checkbox matrices exactly. If the next event has a different number of days, it matches up to the maximum overlapping days and leaves excess days to smart defaults.

---

## 4. State Management & Technical Requirements

### 4.1 Data Structure (JSON Schema Example)
The component should track state in a normalized schema. This ensures simple validation and clear payloads upon form submission.

```json
{
  "volunteer_id": "usr_987654",
  "events": [
    {
      "event_id": "evt_summer_2026",
      "full_attendance": false,
      "schedule": [
        { "date": "2026-08-14", "daytime": true, "overnight": true },
        { "date": "2026-08-15", "daytime": true, "overnight": false },
        { "date": "2026-08-16", "daytime": false, "overnight": null } 
      ]
    }
  ]
}