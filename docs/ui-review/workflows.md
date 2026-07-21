# Workflow Definitions

### Workflow A: Team Formation & Placement

* **Goal:** Match sign-ups to expedition teams while honoring participant availability and preferred teammates.
* **UX Criteria:**
* **Side-by-Side Context:** Can the admin see constraints (availability and requested friends) on the screen *at the exact moment* of assigning a team?
* **Low Friction:** Does team assignment support inline editing or drag-and-drop interactions, or does it require navigating into separate edit screens for each individual explorer?
* **Immediate Validation:** Are constraint conflicts (e.g., date mismatches) flagged automatically upon assignment?



### Workflow B: Readiness Tracking (Training & Routes)

* **Goal:** Track training progress and route submissions across explorers and teams.
* **UX Criteria:**
* **Management by Exception:** Does the interface highlight incomplete items and blockers, or force the user to manually audit full lists of completed items?
* **Batch Operations:** Can group leads approve requirements (e.g., "First Aid Completed") across an entire team in a single action?



### Workflow C: The Parent / Explorer Portal

* **Goal:** Provide clear, scoped status updates to non-admin users on desktop and mobile devices.
* **UX Criteria:**
* **Role-Scoped Projections:** Does the portal display a clean subset of admin data without exposing internal administrative controls?
* **Actionability:** Are outstanding tasks (e.g., consent forms, medical updates) highlighted clearly at the top of the mobile viewport?

### Workflow D: Volunteer & Leader Coverage
* Goal: Coordinate volunteer supervisors and assessors for expeditions based on their availability.
* UX Criteria:
    * Can admins easily match volunteers to specific expedition dates using the availability recorded in ems_volunteer_availability?
    * Is there clear feedback showing whether an expedition has sufficient supervisor coverage?

### Workflow E: Data Sync Control Flow (System UX)
* Goal: Allow leaders to trigger sync actions with Online Scout Manager (OSM) without exposing raw API tokens or breaking UI
responsiveness.
* UX Criteria: Does the interface provide clear loading/progress feedback during OSM import processes and handle failures gracefully?