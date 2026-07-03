# Going forward - 29th June 2026 (Updated Status: 2nd July 2026)

This document outlines the next steps for the Expedition Management System, starting from the current status in [next-steps-plan.md](./next-steps-plan.md).

## Key milestones
1. [x] (Partially Achieved) **Explorer parent signups, payment, and preferences**:
   * [x] Parents can sign up explorers for DofE levels/expeditions via Fluent Forms.
   * [x] Form tracks payment status (Stripe sandbox setup) and pre-populates emails (parent, explorer, leader).
   * [x] Preferences are gathered.
   * [ ] **Remains**: Back-office list page is read-only. Manual processing/marking signups "done" and requesting OSM unit shares from the dashboard needs to be fully implemented.
2. [x] (Partially Achieved) **Assign explorers to dates, form teams, and communicate**:
   * [x] React Expedition Board allows creating seasons/events, forming teams, and dragging-and-dropping/moving explorers.
   * [x] Size checks and warnings (4–7 members) are displayed.
   * [x] Local first-aid status is visible and editable.
   * [ ] **Remains**: Calendar view, student-facing portal, and automated event notifications to parents/explorers/leaders are pending.
3. [x] (Partially Achieved) **Monitor team compliance/health**:
   * [x] Training report lists Tutor LMS completion statuses (Complete, In Progress, Not Enrolled) using optimized batch queries.
   * [x] Event course requirements and first aid qualifications are configurable.
   * [ ] **Remains**: Automatic enrollment of explorers in Tutor LMS courses from EMS is pending (currently queried but not written).
4. [ ] (Pending) **Explorer/Parent Status Portal**:
   * [ ] Front-facing web landing pages showing expedition assignments, teams, routes, and training compliance.
5. [x] (Partially Achieved) **Adult Volunteer Availability**:
   * [x] Database table `ems_volunteer_availability` for storing date availability and sign-offs is registered.
   * [ ] **Remains**: React volunteers admin page is a placeholder stub. Adult-facing signup forms, availability mapping overlays, assignment engines, and notifications are pending.
6. [ ] (Pending) **Unit Leader Sharing Portal**:
   * [ ] Sharing allocation, unit maps, and group kit supply lists with unit leaders.

---

## Architecture Components
*   [x] **Wordpress** - provides expeditions website
*   [x] **Elementor Pro** - site management tools
*   [x] **“Login-with-google”** - custom plugin that provides OIDC login to the website
*   [x] **OSM** - backend storing PII, handles event payments, handles most explorer emails
*   [x] **Tutor LMS Pro** - online learning system
*   [x] **Fluent Forms Pro** - form provider

### Other Wordpress plugins
*   [x] User menus
*   [x] User role editor
*   [x] WP Consent API
*   [x] Complianz
*   [x] Custom Login Page Customizer

---

## Step 1 - get authentication sorted out [x]
**Objective** - Explorers, parents and leaders can sign in and be associated to the correct role.
*   [x] Extend login hooks (`rtcamp.google_user_logged_in` and `rtcamp.google_user_created`) to fetch context.
*   [x] Dynamically assign WordPress custom roles: `ems_parent`, `ems_explorer`, `ems_leader`.
*   [x] Store OSM credentials and mapping metadata in User Meta.
*   [x] Implement zero-persistence token disposal policy.

---

## Step 2 - set up forms [x]
**Objective** - purchase and install fluent forms, create and initial form and set up custom types within the EMS.
*   [x] Integrate Fluent Forms Pro.
*   [x] Implement dynamically populated options (child select from parent's OIDC context).
*   [x] Connect Stripe payment gateway and listen to payment webhook state changes.

---

## Step 3 - build sign up process [x] (Partially Done)
**Objective** - explorers can sign up for DofE levels and expedition. Common edge cases handled and exception process defined.
*   [x] Parent login restriction before form access.
*   [x] Dynamic child selection options from metadata.
*   [x] Sandbox payment processing integration.
*   [x] Hidden pre-populated emails for notifications (parent, explorer, leader).
*   [x] Dynamic mapping of district/unit dropdowns.
*   [x] Form screens to gather preferences and first aid levels.
*   [x] Back office Admin view: Signups list table.
*   [ ] **Remains**: Signups list table is currently read-only; lacks administrative validation actions or push-back to OSM.
*   [ ] **Remains**: Account/SEEE reconciliation dashboard.

---

## Stage 4 - Admin pages view [x] (Partially Done)
**Objective** - Set up admin pages, even if these only have an info message on them for now.
*   [x] **EMS Dashboard Page** (parent menu structure).
*   [x] **Expeditions Board** (SeasonDashboard React SPA).
    *   [x] Season creation, archival, deletion.
    *   [x] Event/expedition creation and updates.
    *   [x] Team setup (sequential numbering, drag-and-drop movement, duplication).
    *   [x] Event calendar dates listing.
*   [ ] **Explorers' Preferences** panel/surfacing.
*   [x] **Explorers List** (OSMReference React SPA).
    *   [x] Synced explorer profile lists.
    *   [x] Training completions status grid (Tutor LMS data integration).
    *   [x] Interactive first aid level editing.
*   [x] **Sign Ups** (PHP database list table).
*   [ ] **Volunteers Board** (UI is currently a placeholder div).
*   [x] **OSM Sync/Reference Views** (multi-tab reference page).
    *   [x] Sync manager logic (manual triggers, status bars, transients).
    *   [x] Flexi-Record Column Mapper UI.
    *   [x] Diagnostics Panel (system data, transients, raw token arrays).
    *   [ ] Account reconciliation tools.
*   [x] **Settings** (General settings tabs).
    *   [x] OAuth configurations (Client ID/Secret Encryption).
    *   [x] Managed sections registry.
    *   [x] Unit leader contacts.

---

## Stage 5 - Sync back to OSM [ ]
**Objective** - Write expedition team data back to OSM.
*   [ ] Implement OSM API Client write operations (flexi-record updates, push updates to `updateScout`).
*   [ ] Re-authentication triggers and fail-safe push-back queues.
*   [ ] Event invitations creation from EMS to OSM.

---

## Stage 5 - Adult volunteers [ ] (Partially Done)
**Objective** - Manage volunteer sign ups.
*   [ ] Availability calendar registration screen.
*   [ ] React dashboard interface for cover mapping and allocations.
*   [ ] Automated volunteer notifications.

---

## Stage 6? - Website [ ]
**Objective** - Set up website pages, even if these only have an info message on them for now.
*   [ ] **Explorer Landing Page** (Assigned teams, routing maps, compliance checklists).
*   [ ] **Parent Landing Page** (Signup forms, active explorer status trackers).
*   [ ] **Leader Landing Page** (Allocated team overviews, kit requirements).
