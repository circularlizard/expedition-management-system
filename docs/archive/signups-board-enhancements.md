# Enhancements to the signup board


## Participant place table contents

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
* ESU (from form -- does not need link logic)
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

Participant place signup statuses should be received, participant place allocated, archived. "Processing" a record is confirming that a participant place has been allocated and entering the eDofE number if it hasn't already been provided. 



## Discussion

Review of the screen has led to an important structural decision. The signup table is being used for 2 purposes that were previously separate, and should remain so.

1. Participation Place signup, by which the explorer signs up with DofE, via SEEE (us)
2. Expedition signup, which used to be a separate form, in which the explorer tells SEEE what expedition they want to do

Each one will have a dedicated form and dedicated processing user interfaces within the EMS, each appropriate to the purpose at hand. Each of the forms will need listeners of the kind already created to find the explorers the parent has access to, populate the unit and the leader's email. The format for storing the explorer's scout ID and name have changed a little from the current implementation.

We need to restructure the database tables, and the application to reflect this. Analyse the impact of this change on the application and create a spec that describes how to make this change, including documentation updates. Advise of any adverse impacts and make me decide if there are any conflicts to resolve.


The purpose of the signup table will be to enable us to process (1) and so it should be focussed on presenting the information needed to allocate participant places. This is manual, there is no API for DofE systems. Once the participant place is allocated

We will have 2 forms, both protected by login such that only those with the ems_parent role can access the form - this will satisfy the need for parents to take responsibility for signing up 




While the signup table (the detail panel) should allow us to view the expedition information, expedition signup is a separate process. 
* We take the list of people who have signed up by a given deadline. We ask all of their respective unit leaders to share the explorers' records in OSM with SEEE (there is not usually a problem with this).
* When the deadline passes, we look at the Explorers' date and team preferences and allocate them to training, practice and qualifier dates. These 
* Once this initial allocation is done, we transfer the allocation information to OSM, where we 
* We send an email to them (via OSM)