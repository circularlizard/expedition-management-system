# Milestone 4: Explorer & Parent Front-Facing Web Portal

In general, the explorer and parent portals should mirror the information filtered so that Explorers only see information corresponding to their own records, and parents only see information corresponding to the explorers that they have parent relationships for.

## Explorer Portal

Explorers need a page that 
* Lists their participant place applications, expedition applications and lets them see the details of what was submitted.
* Shows their current expedition sign ups - trainings, qualifiers and practices. Normally there should only be one practice and one qualifier, but there may be multiple trainings
	* We should have a tab for each of the types of event. 
	* If there is only one active event in the category then details of that event need to be directly displayed. 
	* If there are multiple events a list should be displayed first, with details available on event selection.
	* If there are no events of a given type, grey the tab out.
* For training events we need to display event details - start and end time, location, link to OSM event, leader in charge details
* For practice and qualifier expeditions, we need to display tabs showing all of the expedition details - more or less exactly as in the internal EMS view but scoped to a single explorer
	* Start & end time & location
	* Event types
	* Leader in charge
	* Training requirements / completion, with live links to training courses
	* Route planning
	* Team mates
	* WhatsApp group links
	* Later we will add attachments like risk assessments, plan doc, this can be stubbed for now
	* Later we will add route submission features, this can be stubbed for now

## Parent view
Parent view should be the same as the explorers’ except that
* Participant place and expedition form submissions should show all of their children’s forms together
* Training, practice and qualifier details must be segmented by child - select the child first then see the same details that the child does
* Training completion records should be shown for the selected child, not for the logged in user

## Non-functionals
Styling should inherit from the parent theme (Hello Elementor)