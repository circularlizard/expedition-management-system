# Issues to resolve

## Participant Places
* Prior level isn't being correctly read any more. Displaying only X when the database shows other data. Root cause seems to be a change in how the completion data is stored between the mock data and the actual form submission.
Mock data - {"volunteering":"completed","skills":"none","physical":"completed","expedition":"completed"}
Actual data - ["Volunteering","Skills","Physical","Expedition","None"] - array shows only those items checked on the form.
Result is, for some reason an "X" in the UI showing that the person didn't do an award, not the sections they have actually declared complete.

Need to correct both the sample data and the logic that reads it. Let's 

* change the formatting of the red X showing no prior award to show a red ❌ with no circle, to distinguish it from the other statuses
* The close icon on the inspector panel has a border and it should not. The border attribute of the button-link style is overridden by that of the button style class. Other similar items are not styled with both classes.

## Expeditions Page

### Upcoming Events Dashboard
- Archive button should have a red border
- Need an edit button directly on the expedition list, that goes to the edit expedition screen

### Event Planner
- Explorer availability should have a column showing the explorer's team preferences from the expedition signup form, will need to be in fairly small text

### Expedition Detail
- Leave a margin below the map before the LIC details
- Change the title of the "notes" field to "Route Information"
- RTE should include style selector
- Teams cards have the number of participant is the header right up against the team title. Put in a space and put the number in brackets
- The move selector only shown new teams after the page is reloaded
- We need to be able to take bulk actions, to select explorers and reassign them
- The add member list should be sorted alphabetically
- The team page should have a legend for the first aid symbols

- On the additional support notes page, we don't need a save button on each field, one for the whole page will do.
- The Organisers' notes aren't being persisted.
- On the training tab, the table should sorted alphabetically by first name. It should also show the explorer's unit.

## Explorers Page
This should be rebuilt using the same table / inspector format that is used for the signup pages.
The main table should be as at present.
The inspector should join the explorer data synced from OSM with that from the expedition and participant place signup forms to show
- first name / last name
- scout ID
- email address
- unit
- unit leader email (from units table, based on patrol)
- training events inc OSM acceptance status
- practice events inc OSM acceptance status
- qualifiers events inc OSM acceptance status
- ASN inc editable leaders' notes
- expedition available dates and team preferences
- training records
- table of participant place signups, most recent first - level, submission time and status, record linking to 

## Expedition Signup Form
The form has been updated, and the dates used to populate the expedition_preferences in the wp_ems_expedition_signups table are now in these fields - 
exped-silver-practice-dates, exped-gold-practice-dates
exped-silver-qualifier-dates, exped-gold-qualifier-dates

The form data mapping should be read from config that's available in the UI on the settings tab.
