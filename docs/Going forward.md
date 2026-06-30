# Going forward - 29th June 2026

This document outlines the next steps for the Expedition Management System, starting from the current status in [next-steps-plan.md](./next-steps-plan.md).

## Key milestones
1. An explorer’s parent can sign them up for the next level of DofE, we can take payment, and expedition preferences are gathered at the same time. These need to become available for manual back office processing, and we need to mark which ones have been done. This should include notifying unit leaders that someone has signed up and requesting OSM share.
2. From those who have signed up for expedition, we need to assign explorers to dates, form teams, and communicate this to Explorers / parents / leaders. This should cover progressive discovery, as we go from dates to teams to training assignments, to route planning etc. This should also enable us to note ASN, first aid requirements and see team status. We’ll need to create and manage a calendar of events to do this.
3. We can monitor the health of a team in terms of first aid status, training completions etc. To do this we’ll need to specify what the requirements are, which will involve enrolling explorers in the tutor lms courses.
4. We can show explorers/parents their status on a web page, which lists their expedition assignment and all of the information about that as it becomes available.
5. We can ask adults for their availability to help with expeditions. We can map this to the expeditions and identify  which expeditions lack coverage. We can notify people they’ve been assigned, and they can login to a web page where they can see what they have signed up for.
6. We can share sign up status with unit leaders, let them see what kit they have been asked to supply, where explorers in their units are allocated, etc.

## Architecture Components
Wordpress - provides expeditions website
Elementor Pro - site management tools
“Login-with-google” - custom plugin that provides OIDC login to the website
OSM - backend storing PII, handles event payments, handles most explorer emails
Tutor LMS Pro - online learning system
Fluent Forms Pro - form provider

Other Wordpress plugins
- User menus
- User role editor
- WP Consent API
- Complianz
- Custom Login Page Customizer

## Step 1 - get authentication sorted out
**Objective** - Explorers, parents and leaders can sign in and be associated to the correct role.

We need to extend the login hooks already in the EMS to determine the role the user has, set them up correctly if that hasn’t been done already and store sufficient meta data that later processes work properly.


## Step 2 - set up forms
**Objective** - purchase and install fluent forms, create and initial form and set up custom types within the EMS.

Fluent forms is the major missing part of this architecture. We need to get it deployed and working, with some level of customisation from the EMS working so that all parts of the system are finally deployed and working.

This should include the elementor integration plugin.

## Step 3 - build sign up process
**Objective** - explorers can sign up for DofE levels and expedition. Common edge cases handled and exception process defined.

User must sign in as a parent to see sign up form
Form must present list of children for that parent
Pre-populate form with selected child (skip selection if there’s only one)
Payments integration (dummy)
Email confirmation to parent, explorer and section leader (dummy)
Linked selection of district, unit, leader email (define as custom component)
Form screens to gather expedition preferences, first aid status

End result is sign up form with screens in EMS 
- who has signed up
- participation place status
- expedition sign up status
- expedition preferences
- aggregated expedition preferences
- SEEE section reconciliation

Emails to units requesting share (dummy at this stage)
Emails to units confirming sign up (dummy at this stage)

## Stage 4 - Admin pages view
**Objective** - Set up admin pages, even if these only have an info message on them for now

ESM
- Expeditions
	- Expedition Calendar
	- Team set up (already built) (possibly to be merged with expedition detail)
	- Expedition Detail (already built) (possibly to be merged with team set up)
	- Explorers’ Preferences -- need to consider how and where to surface these
- Explorers
	- Sign Ups
	- Explorer view (already built)
	- Training view (already built)
- Volunteers
	- Sign Ups
	- Expedition Assignment
- OSM Sync
	- OSM data (built)
	- Flexi record mapping (built, but not working)
	- Sync manager (spread in multiple places)
	- Account reconciliation
- Settings
	- Oauth settings (built)

Then we’ll build out these screens except for volunteer sign up, which can wait later. Flexi record mapping will come in the next step.

## Stage 5 - Sync back to OSM
**Objective** - Write expedition team data back to OSM

Map section to flexi record
Do we want to do event invites from EMS

## Stage 5 - Adult volunteers
**Objective** - Manage volunteer sign ups

Forms to allow adults to sign up to help, see what they have signed up for.
Views for us to see who has signed up, send reminders, and assign adults to expeditions.

## Stage 6? - Website
**Objective** - Set up website pages, even if these only have an info message on them for now

Website
- Explorer landing page
	- What I am signed up to
	- My team
	- Training
- Parent landing page
	- Sign up form
	- My explorer
- Leader landing page
	- Sign up form (public)
	- What I have signed up to
	- My expeditions





