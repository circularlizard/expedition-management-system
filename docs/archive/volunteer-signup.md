# **UI/UX Specification: Multi-Day Volunteer Availability Enrollment System**

## **1\. System Overview**

* **Purpose:** To capture detailed volunteer availability for 8–10 multi-day outdoor/expedition events without overwhelming users with a massive grid layout.  
* **Core UX Philosophy:** **Progressive Disclosure & Smart Defaults**. Reduce immediate cognitive load by asking macro-level questions before revealing micro-level scheduling details.  
* **Target Device Support:** Mobile-responsive design (Touch-friendly tap targets, linear stacking on small viewports).

## **2\. User Journey Flow**

1. **Step 1: Event Filtering (Macro-Selection)** – User selects which dates or events they are *potentially* able to support. Overlapping events are grouped together instantly.  
2. **Step 2: Availability Builder (Micro-Selection)** – Sequential, deep-dive configuration for *only* the selected date blocks using accordion panels or a wizard interface.  
3. **Step 3: Review & Submit** – A concise summary page showing confirmed slots, with options to adjust before final submission.

## **3\. Concurrent & Overlapping Event Logic (The "Date Bundle" Pattern)**

### **3.1 Backend Rule & Grouping Mechanics**

Before rendering Step 1 or Step 2, the frontend application must run a grouping function on the events database payload.

* **Condition:** If two or more events share identical start and end dates, they are flagged as concurrent\_group \= true and assigned a shared date\_group\_id.  
* **UX Strategy:** Treat physical availability as a function of the **calendar dates**, and project assignments as a secondary **preference**. The user specifies when they are physically free first, and which project they prefer second.

## **4\. Detailed Component & UI Interface Specifications**

### **4.1 Step 1: Macro-Selection Screen (Event Filter)**

* **Goal:** Eliminate irrelevant dates early so the user never sees scheduling grids for weekends they are completely busy.  
* **UI Components:**  
  * **Header Section:** Clear instructions text: *"Which of these upcoming events could you potentially support? Don't worry about exact hours yet—just pick the dates that generally work for you."*  
  * **Event Card Grid:** A list of stacked cards combining standalone events and concurrent date blocks.  
  * **Standard Single Event Card:** Shows Event Title, Date Range, and Location with a large toggle checkbox.  
  * **Concurrent Event Card Layout (Stacked Compound Card):**  
    When events overlap, they display as a single unified timeframe block to prevent confusion about double-booking:

\+-------------------------------------------------------------+  
| \[x\] AUGUST 14-16 (CONCURRENT EVENTS)                        |  
|     You have multiple events happening this weekend:        |  
|                                                             |  
|     \[ \] Event A: Highland Trek (Bronze Group)               |  
|     \[ \] Event B: Mourne Mountains Basecamp Support          |  
|                                                             |  
|     (\*) I'm available for EITHER event                      |  
|     ( ) I only want to opt-into specific events             |  
\+-------------------------------------------------------------+

* **Interaction Logic:**  
  * Checking the main card selects the entire block. By default, the sub-selection radio defaults to *"I'm available for EITHER event"*.  
  * If no cards are checked, the "Next" button remains disabled.  
  * Clicking "Next" dynamically generates the wizard steps for Step 2 based *only* on the checked dates.

### **4.2 Step 2: The Multi-Step Availability Builder**

For the selected events or date blocks, users progress through an accordion-style interface or a multi-page wizard. Each active section is presented as a distinct container.

\+-------------------------------------------------------------+  
| DATE BLOCK: AUGUST 14-16                                    |  
\+-------------------------------------------------------------+  
| Q1: Are you available for the entire duration?              |  
|     ( ) Yes, sign me up for all days and nights\!            |  
|     (\*) No, I have partial availability                     |  
|                                                             |  
| \[ Reveal Schedule Matrix below only if "Partial" is chosen \] |  
|                                                             |  
| Bulk Actions:  \[ \[x\] All Daytime \]   \[ \[ \] All Overnights \] |  
|                                                             |  
|   FRIDAY 14th          SATURDAY 15th        SUNDAY 16th     |  
|  \+--------------+     \+--------------+     \+--------------+ |  
|  |   Daytime    |     |   Daytime    |     |   Daytime    | |  
|  | \[Available\]  |     | \[Available\]  |     | \[Unavailable\]| |  
|  \+--------------+     \+--------------+     \+--------------+ |  
|  |  Overnight   |     |  Overnight   |     |              | |  
|  | \[Available\]  |     | \[Unavailable\]|     | (No Night)   | |  
|  \+--------------+     \+--------------+     \+--------------+ |  
| \----------------------------------------------------------- |  
| EVENT ASSIGNMENT PREFERENCE                                 |  
| This weekend has multiple running events. Please specify:   |  
|                                                             |  
| (•) Match me to ANY event where help is needed most         |  
| ( ) I have a specific preference:                           |  
|     \[x\] Event A: Highland Trek (Bronze)                     |  
|     \[ \] Event B: Mourne Mountains Basecamp                  |  
\+-------------------------------------------------------------+  
| Utility: \[ Copy schedule configuration to next event \]      |  
\+-------------------------------------------------------------+

#### **A. Smart Default Triage (The "Gatekeeper" Question)**

Before showing any time slots, display two primary radio choices:

1. **Full Availability:** *"I can do the whole event (All days & nights)."*  
   * *Action:* Keeps the detailed grid hidden, auto-checks all sub-slots behind the scenes, and lets the user advance immediately.  
2. **Partial Availability:** *"I need to pick specific days or times."*  
   * *Action:* Smoothly expands the **Visual Shift Builder Matrix** and project preference options below via a CSS transition animation.

#### **B. Visual Shift Builder Matrix (Expanded View)**

* **Layout:**  
  * Columns represent chronological days (e.g., **Friday 14th**, **Saturday 15th**, **Sunday 16th**).  
  * Rows represent shifts: **Daytime** and **Overnight**.  
  * *UX Rule for Overnights:* To prevent calendar confusion, "Overnight" is anchored to the day it *begins*. For a 3-day event (Fri/Sat/Sun), there are 3 Daytime slots (Fri, Sat, Sun) and 2 Overnight slots (Fri Night, Sat Night). The Sunday column visually omits the overnight row because there is no overnight shift after the event concludes.  
* **Block Elements (Tap Targets):**  
  * Shifts are rendered as large, touch-friendly rounded rectangles (min-height: 48px).  
  * **State Styling:**  
    * *Available (Checked):* Colored background (e.g., desaturated forest green), checkmark icon, bold text label.  
    * *Unavailable (Unchecked):* Light grey/off-white background, subtle grey border, standard text label.  
* **Bulk Toggles (Micro-Accelerators):**  
  * Provide two small toggle links/buttons right above the columns: Select All Daytime and Select All Overnights. This allows a user who can do all days but zero nights to configure the entire event matrix with exactly two taps.

#### **C. Project Assignment Preference Selection (Conditional UI)**

If the card represents a **Concurrent Date Block**, display an explicit preference section directly below the grid:

* **Default State:** Option Match me to ANY event where help is needed most is pre-selected.  
* **Override State:** If the user selects I have a specific preference, display conditional checkboxes mapping out each distinct event running on those dates, allowing them to explicitly uncheck projects they cannot or do not want to support.

#### **D. Cross-Event Utilities (The "Copy Configuration" Feature)**

* **Component:** A button or checkbox at the footer of Event/Date Block \#2 onwards stating: \[ \] Copy my availability from the previous event.  
* **Interaction Logic:** When checked, the system inspects the matrix state of the preceding event. If the day counts match, it duplicates the Daytime/Overnight checkbox matrices exactly to drastically reduce data entry time.

## **5\. State Management & Technical Requirements**

### **5.1 Data Structure (JSON Schema Example)**

The component tracks state in a unified, normalized array. If an entry encompasses concurrent events, it includes all targeted IDs within the assigned\_event\_ids array, governed by the user's assignment\_strategy.

{  
  "volunteer\_id": "usr\_987654",  
  "schedules": \[  
    {  
      "date\_group\_id": "grp\_aug\_14\_16",  
      "full\_attendance": false,  
      "assignment\_strategy": "any",   
      "assigned\_event\_ids": \["evt\_highland\_trek", "evt\_mourne\_basecamp"\],  
      "schedule": \[  
        { "date": "2026-08-14", "daytime": true, "overnight": true },  
        { "date": "2026-08-15", "daytime": true, "overnight": false },  
        { "date": "2026-08-16", "daytime": false, "overnight": null }   
      \]  
    }  
  \]  
}

*Note: overnight: null denotes that the event ends on that afternoon and no overnight shift exists on the final calendar day.*

### **5.2 Local Auto-Save (Persistence)**

* **Trigger:** The system must commit the current component state to localStorage or execute an async background cache update to the backend engine every time a user clicks "Next" or collapses an accordion section.  
* **Benefit:** Protects volunteer time-investment against accidental page refreshes or mobile browser timeouts over a long, multi-step configuration journey.

### **5.3 Validation Rules**

* An event/date block marked as "Selected" in Step 1 must have at least one shift block checked in Step 2, OR explicitly retain the "Full Availability" toggle state.  
* If a user deselects every single block inside an expanded matrix, show an inline validation warning message: *"You've unselected all times. If you are no longer available for this weekend, please uncheck it from the list."*

## **6\. Visual Design Guidelines (CSS System)**

* **Typography:** Accessible sans-serif font family. Headings bolded, shift blocks clean and highly readable.  
* **Accessibility (WCAG 2.1 AA):**  
  * Ensure target size for shift blocks is at least 44px x 44px.  
  * Active vs Inactive states must not rely on color alone; use visual markers like explicit text changes (\[ Available \] vs \[ Click to add \]) or status iconography (checkmarks vs plus signs).  
* **Motion/Micro-interactions:** Use smooth vertical slide transitions (transition: max-height 0.3s ease-out) when expanding partial availability grids to make the UX feel organic rather than jarring.