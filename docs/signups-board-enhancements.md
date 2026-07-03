# Enhancements to the signup board


## Summary table contents

Current contents
* Explorer name
* Level
* ESU
* Payment status
* Link status
* DofE number
* Action buttons

Amend contents of table as follows
* Submission date/time
* Explorer name
* Level
* ESU (from form -- does not need a link)
* Explorer Email Address
* Prior level status (show icons for V, S, P, E - white letter in a coloured circle if section completed, nothing otherwise, red X if None is checked. V - red, S - blue, P - yellow, E - green)
* DofE number (if provided)

## Explorer detail panel

Clicking on a record in the table should open an inspector pane for the Explorer. This should have the following general actions
* Close button
* Back/forward buttons to page through unprocessed entries

The key actions on this panel are
1. Mark that a participation place has been allocated for the requested level
2. Enter eDofE number if none provided

The panel should show all of the form submission information, including the expedition preferences, in an organised format. The same colours and icons from the main page should be used. 

## Discussion

The signup table is being used for 2 purposes that were previously separate, and we should consider whether these need to be stored separately.

1. Participation Place signup, by which the explorer signs up with DofE, via Scouts Scotland
2. Expedition signup, which used to be a separate form, in which the explorer tells us what expedition they want to do

The purpose of the signup table will be to enable us to process (1) and so it should be focussed on presenting the information needed to allocate participant places. This is manual, there is no API for DofE systems. Once the participant place is allocated

While the signup table (the detail panel) should allow us to view the expedition information, expedition signup is a separate process. 
* We take the list of people who have signed up by a given deadline. We ask all of their respective unit leaders to share the explorers' records in OSM with SEEE (there is not usually a problem with this).
* When the deadline passes, we look at the Explorers' date and team preferences and allocate them to training, practice and qualifier dates. These 
* Once this initial allocation is done, we transfer the allocation information to OSM, where we 
* We send an email to them (via OSM)