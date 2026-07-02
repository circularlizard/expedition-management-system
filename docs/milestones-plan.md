# EMS Milestone Roadmap & Execution Plan

This document outlines the roadmap for the remaining deliverables of the Expedition Management System (EMS). It details what has been achieved for each milestone, what needs to be implemented next, and serves as a planning workspace to expand on requirements.

---

## Milestone 1: Signup Processing & Unit Leader Outreach
*Collects DofE levels, payment validation, and expedition preferences, enabling manual admin processing and leader notification.*

*   **Achieved**:
    *   Dynamic parent-child dropdown fields populated on registration forms.
    *   Stripe payment hooks connecting sandbox gateway state changes to local record statuses.
    *   Automatic resolution of ESU units, pre-populating parent, explorer, and leader emails in hidden fields.
    *   Read-only back-office list view (`ems_signups` table mapping).
*   **Next Steps (Remains)**:
    *   [ ] Add actionable triggers to the Signups list view to mark registrations as "Processed" or "Archived". Capture DofE registration data for new registrations.
    *   [ ] Configure fluent forms to email unit leaders requesting their OSM section share when a child signs up.
    *   [ ] Add DOB to the fluent form for signup
    *   [ ] Add views summarising explorer date preferences
    *   [ ] Implement a reconciliation view (Fluent Forms vs. OSM Sync reference lists). Need to have a single master explorer view with reconciliation status, not multiple lists (eg use icons to indicate the various statuses)
    *   [ ] Implement a data panel in the explorers list that shows all data, incl exped preferences, ASN and teammate preferences. Same pattern can be used for other detail panes later.
    *   [ ] **Test Data Seeding**: Create a signup test data generator. Generate multiple variants of form submissions to represent realistic test scenarios (different levels, payment statuses, and units), and scale this using AI expansion to produce a large, diverse dataset.

---

## Milestone 2: Team Formation, Event Dates & Calendar Management
*Assigns explorers to specific dates and teams, and handles progressive details (dates → teams → route cards).*

*   **Achieved**:
    *   React Expedition Board SPA allowing seasons, events, and teams CRUD.
    *   Interactive drag-and-drop movement of explorers between teams/events.
    *   Automatic sequential team code generation (e.g. `H-SP1-1`).
    *   Contiguous layout and team size warning validation (checks for 4–7 members).
*   **Next Steps (Remains)**:
    *   [ ] Implement a calendar dashboard interface showing chronological timelines of all practice/qualifying events.
    *   [ ] Add communication notifications (emails or alerts) triggered when explorers are assigned to dates or teams.
    *   [ ] Track Additional Support Needs (ASN) alongside team details.

---

## Milestone 3: Compliance & Training Progress Monitoring
*Specifies training requirements for expeditions, validates completions, and monitors team health.*

*   **Achieved**:
    *   Course requirement configuration per event in Post Meta.
    *   Compliance status overview grid mapping explorers to completion states.
    *   Batch-optimized Tutor LMS matrix retrieval completing in only two DB queries.
    *   First aid status tracking (configurable levels: `none`, `first_response`, `full_first_aid`) on explorer rosters.
*   **Next Steps (Remains)**:
    *   [ ] Develop an automated enrollment script that hooks into EMS team assignments to register explorers in corresponding Tutor LMS courses.
    *   [ ] Implement automatic warnings on the Expedition Board when a team does not have a member with an active first aid qualification.
    *   [ ] **Test Data Seeding**: Build a mock data generator for Tutor LMS completion and enrollment states (Complete, In Progress, Not Enrolled) to test training compliance matrices at scale.

---

## Milestone 4: Explorer & Parent Front-Facing Web Portal
*Exposes expedition details, team status, route cards, and training progress to participants.*

*   **Achieved**:
    *   Custom Page Templates (`ems-page-template.php`) registered for rendering.
    *   Theme headers/footers mapping support.
*   **Next Steps (Remains)**:
    *   [ ] Build the `[ems-explorer-portal]` shortcode SPA showing:
        *   Assigned events and dates.
        *   Teammate lists.
        *   GPX and route card submission history.
        *   Tutor LMS training checklists.
    *   [ ] Build the `[ems-parent-portal]` shortcode SPA showing child progress trackers and signup options.

---

## Milestone 5: Adult Volunteer Availability Mapping
*Gathers adult availability, maps volunteer coverage across dates, and notifies cover assignments.*

*   **Achieved**:
    *   Database table `ems_volunteer_availability` handles schema mapping.
    *   Volunteer admin menu registered.
*   **Next Steps (Remains)**:
    *   [ ] Build the volunteer signup front-facing form.
    *   [ ] Enqueue React components on the Volunteers Admin page to render an availability scheduling grid.
    *   [ ] Build an assignment engine to link volunteers to events and alert them of scheduled cover.

---

## Milestone 6: Unit Leader Integration Portal
*Provides unit leaders with visibility into allocation, signup states, and kit requirements.*

*   **Achieved**:
    *   ESU unit leader contact configurations mapping directory.
*   **Next Steps (Remains)**:
    *   [ ] Create a Unit Leader portal showing all explorer allocations from their ESU unit.
    *   [ ] Implement kit lists tracking tool mapping equipment requests to specific units.

---

## Milestone 7: Offline/Online Scout Manager Write-Back (Push-Back Sync)
*Pushes team selections and group assignments back into Online Scout Manager flexi-records.*

*   **Achieved**:
    *   OAuth client-credentials structure mapping.
    *   Local tables tracking dirty states (`last_local_update_at`).
*   **Next Steps (Remains)**:
    *   [ ] Implement write operations in `OSM_API_Client` (`updateScout` endpoint POST delivery).
    *   [ ] Build the failed write-back recovery dispatcher (`ems_failed_pushback_queue` processor).
