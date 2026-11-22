# Target Domain Model, Personas & Information Architecture

**Document Status:** Design Baseline (Step 1)  
**Scope:** System boundaries, personas & access rules, target `wp-admin` and front-end Information Architecture (IA), and UX/engineering standards for `ems-plugin`[cite: 2, 4].

---

## 1. System Boundaries & Master Records

The EMS is a focused operational layer sitting alongside Online Scout Manager (OSM), Tutor LMS, Fluent Forms, and Elementor[cite: 5, 7]. It may sync information from OSM to assist with management, but all information should have a single master.[cite: 5].

| Domain Concern | System of Record (Master) | EMS Role & Sync Direction |
| :--- | :--- | :--- |
| **Full PII, medicals, emergency contacts, risk assessments, payments** | **Online Scout Manager (OSM)**[cite: 5] | **Read-only cache** (`ems_osm_explorers`) for operational matching & First Aid checks[cite: 3, 7]. Never edited in EMS. |
| **Sign-up intake (Place & Expedition preferences)** | **Fluent Forms (Forms 6 & 7)**[cite: 7] | **Webhook ingestion** into `ems_participant_signups` and `ems_expedition_signups`, followed by OSM reconciliation[cite: 3, 7]. |
| **Team membership & Expedition allocation** | **EMS (`ems_teams` / `ems_team_members`)**[cite: 3, 5] | **Single Source of Truth[cite: 5].** One-way push of confirmed allocations to OSM events & `'2026 Expeditions'` flexi-record[cite: 5]. |
| **Online training delivery & completion** | **Tutor LMS**[cite: 7] | **Read-only adapter** (`TutorLmsAdapter`) queried via `ems_scout_ids` for readiness & cron reminders. |
| **Route planning, GPX enrichment & LiC approval** | **EMS (`RouteEngineModule`)**[cite: 2, 3] | **Single Source of Truth.** Shared team editing, 14-day cutoff enforcement, LiC annotation, and A4 PDF generation[cite: 3, 6]. |
| **Public pathway, costs & marketing pages** | **WordPress / Elementor** | External pages (`/expedition-roadmap/`, `/signup/`, `/training/`, `/costs/`). EMS supplies self-contained shortcodes only[cite: 6, 7]. |

---

## 2. Canonical Persona & Access Matrix

Six distinct personas interact with the EMS. Every REST controller and UI view enforces these boundaries via explicit policy classes (`TeamAccessPolicy`, `RouteCardAccessPolicy`, `UnitScopePolicy`).

| Persona | Auth Mechanism & WP Role | Identity Anchor | Permitted Capabilities | Explicit Restrictions & Redactions |
| :--- | :--- | :--- | :--- | :--- |
| **1. Prospective Participants and their families** | Unauthenticated (`public`) | None | Submit Form 6 / Form 7, submit `[ems-volunteer-signup]`[cite: 6, 7]. | Zero read access to rosters, units, or portals. |
| **2. Explorer (Participant)** | OSM OIDC (`ems_explorer`)[cite: 7] | `ems_scout_ids` $\rightarrow$ `ems_osm_explorers.scout_id`[cite: 2, 7] | View sign-up status; view Practice/Qualifier dates & teammates; **Accept / Query (with message) / Decline** Place Offer (by 31st Dec); view Tutor LMS & First Aid status; **co-edit & submit shared team Route Cards** (before 14-day cutoff)[cite: 3, 6, 7]. | Scoped strictly to own `scout_id` and own `team_id`. Cannot view teammates' medical/ASN notes or edit other teams' routes. |
| **3. Parent / Guardian** | OSM OIDC (`ems_parent`)[cite: 7] | `ems_children` / `ems_scout_ids`[cite: 2, 7] | Multi-child switcher in `[ems-portal]`; view linked children's sign-ups, training, and expedition dates; **Accept / Query / Decline** Place Offers on child's behalf; view team Route Cards; receive CC on allocation & route emails[cite: 3, 6, 7]. | Scoped strictly to linked children (`ems_children`). Cannot alter other families' records. |
| **4. Unit Leader (DofE Leader)** | Local WP Login (`ems_leader`)[cite: 2, 7] | `ems_units.leader_email` / assigned `unit_id`[cite: 2, 7] | View sign-up & reconciliation status for Explorers in their Unit; view Teams & Expeditions containing Explorers from their Unit (`[ems-leader-dashboard]`)[cite: 1, 7]. | **Strict PII redaction:** medical/ASN details, personal phone numbers, and other units' rosters are stripped server-side[cite: 7]. Read-only. |
| **5. Leader in Charge (LiC) & Volunteer** | OSM OIDC **or** Local WP Login (`ems_leader` / `ems_volunteer`)[cite: 7] | `ems_volunteers.id` / `expedition` LiC assignment | **Volunteers:** manage availability & DBS profile[cite: 7]. **LiCs:** view assigned expeditions, volunteer roster, and team readiness (First Aid Rule of Two, training, acceptances); **inspect, annotate, approve, or request revisions on Route Cards** via `[ems-lic-dashboard]`[cite: 3, 6]. | Never enters `wp-admin`. Scoped strictly to expeditions they are assigned to lead/staff. |
| **6. EMS Coordinator (Admin)** | OSM OIDC or Local WP Login (`administrator` / `manage_options`) | `wp_users.ID` | Full operational control: Form 6/7 reconciliation, OSM sync & pushback, cross-stage Team Builder, manual Place Offer dispatch, volunteer staffing, email templates, backups, and audit logs[cite: 3, 5, 7]. | Cannot mutate OSM master PII directly inside EMS. |

---

## 3. Target Information Architecture (IA)

### 3.1 WordPress Admin (`EMS` Top-Level Menu — 7 Domain Hubs)
Consolidates the current 11 fragmented/duplicated admin pages (`ems-explorers`, `ems-reference`, `ems-column-mapper`, `ems-training-report`, etc.) into 7 clean, single-purpose React hubs:

| Menu Position | Menu Title | Slug | Primary Tabs / Views | Absorbs / Retires |
| :--- | :--- | :--- | :--- | :--- |
| **1 (Top)** | **Sign-ups** | `ems` | `Kanban Board` (`To Process`, `On Hold`, `Returned`, `Processed`, `Cancelled`), `All Sign-ups Table`, `Unmatched OSM (scout_id=0)`, `Data Consistency & Chasers`[cite: 2, 3, 6] | Replaces flat `submitted\|archived` toggle with full 5-stage Kanban[cite: 2, 6]. |
| **2** | **Expeditions & Teams** | `ems-expeditions` | `Events & Calendar`, `Team Builder` (cross-stage continuity + manual Place Offer modal), `Teams & Readiness` (offers, First Aid Rule of Two, Tutor LMS, route status)[cite: 3] | Absorbs `ems-training-report` into `Teams & Readiness`[cite: 2]. |
| **3** | **Volunteers** | `ems-volunteers` | `Expedition Coverage` (day/overnight staffing), `Volunteer & DBS Registry`[cite: 3, 7] | Adds missing DBS verification UI[cite: 2]. |
| **4** | **Units & OSM Sync** | `ems-units-sync` | `Units & DofE Leaders`, `Explorers & Patrols`, `Events & Pushback`, `Flexi-Record Mapper`, `Sync Diagnostics`[cite: 2, 7] | Consolidates `ems-units`, `ems-explorers`, `ems-reference`, and hidden `ems-column-mapper`[cite: 2]. |
| **5** | **Route Studio** | `ems-route-studio` | `Route Sandbox`, `OS Terrain 50 DEM Manager`, `Route Engine Settings`[cite: 2, 3] | — |
| **6** | **Emails & Notifications** | `ems-emails` | `Templates ({{tokens}})`, `12-Month Dispatch Archive`, `Automated Cron & Chaser Rules`[cite: 2, 3, 5] | Retires dead `Unit_Leader_Page::render_email_tab()`[cite: 2]. |
| **7** | **Settings & System** | `ems-settings` | `OSM Connection`, `Fluent Forms Mapping`, `Access Control & Roles`, `Feature Flags`, `Backups & Parity`, `Audit Logs`, `Shortcode Reference`[cite: 2, 4, 6, 7] | Retires legacy `ems_save_leader_invite` / `ems_save_chaser_emails` handlers[cite: 2]. |

### 3.2 Front-End Shortcodes (Persona Gateways)
All front-end shortcodes render isolated React SPAs wrapped in `<ModuleErrorBoundary />` and `Shortcode_Boundary`[cite: 4].

| Shortcode | Target Persona | Tabs / Capabilities |
| :--- | :--- | :--- |
| **`[ems-portal]`** | Explorers & Parents (with multi-child switcher)[cite: 7] | 1. **My Sign-ups:** Form 6/7 status & OSM account link indicator.<br>2. **My Expeditions:** Assigned Practice & Qualifier dates, teammate roster, and **All-or-Nothing Place Offer Bar** (`Accept`, `Query` with message box, `Decline` — locked after 31st Dec)[cite: 3, 6].<br>3. **Training:** Award-level Tutor LMS module checklist & First Aid expiry.<br>4. **Route Planner:** Embedded shared team Route Card editor (DEM profile, Naismith pacing, GPX import/export), `Submit for Review` (enforcing 14-day cutoff), version history, and LiC feedback thread[cite: 3, 6]. |
| **`[ems-leader-dashboard]`** | Unit Leaders (`ems_leader`)[cite: 7] | 1. **Unit Sign-ups:** Reconciliation & eDofE status for Explorers in the leader's Unit (medical/contact PII redacted)[cite: 6, 7].<br>2. **Unit Teams & Expeditions:** Read-only `<TeamReadinessTable />` filtered to teams containing Explorers from the leader's Unit[cite: 3]. |
| **`[ems-lic-dashboard]`** | Leaders in Charge (OIDC or local WP login) | 1. **My Expeditions:** Assigned Practice/Qualifier events, joining details & volunteer roster.<br>2. **Team Readiness Audit:** `<TeamReadinessTable />` scoped to the LiC's assigned events.<br>3. **Route Card Review:** Interactive map & Naismith inspection, inline feedback comments, and `Approve` / `Request Revisions` actions[cite: 3, 6]. |
| **`[ems-path-finder]`** | Prospective Families (Elementor pages)[cite: 6] | Interactive Bronze / Silver / Gold eligibility & pathway selector linking to Elementor `/expedition-roadmap/`, `/training/`, `/costs/`, and `/signup/` anchors[cite: 6]. |
| **`[ems_signup_banner]`** | Form Visitors (above Form 6 & 7)[cite: 7] | Prompts OIDC login to auto-populate child & unit fields[cite: 7]. |
| **`[ems-volunteer-signup]`** | Adult Volunteers[cite: 7] | 4-step availability, overnight preference, and DBS registration wizard[cite: 2, 7]. |

---

## 4. Core Business Rules & Lifecycles

1. **Two-Tier Configurable Deadlines (Zero Hardcoded Dates):**
   * Every time-bound rule separates the **Communicated Deadline** (shown in portals, emails, and cron reminders) from the **Hard Cutoff** (enforced by REST endpoints and UI locks), stored in `ems_deadline_policies` and editable under **Settings & System → Deadlines & Cutoffs**.
   * **Place Offer Policy:** Communicated acceptance date (e.g., mid-December) vs. hard portal lock date (e.g., 31st December `23:59`).
   * **Route Card Policy:** Communicated submission offset (e.g., `T - 21 days` before expedition start, triggering cron reminders) vs. hard edit/submission lock offset (e.g., `T - 14 days`, enforced in `Route_Card_Controller` unless an Admin grants a per-team extension).
   * **Training & First Aid Policy:** Communicated completion offset (e.g., `T - 28 days`, triggering cron reminders) vs. hard readiness gate offset (e.g., `T - 7 days`).
   * **Sign-up Chaser Policy:** Soft chaser offset after Form 6/7 submission (e.g., `+7 days`) vs. escalation offset (e.g., `+21 days`).
2. **All-or-Nothing Place Offers:**
   * A Place Offer covers a Team's combined Practice & Qualifier schedule (with explicit notice if a `date_conflict_pending` override applies).
   * Either the Explorer or a linked Parent can submit a single response (`Accept`, `Query` with message, or `Decline`) before the configured Place Offer hard cutoff. Submitting `Query` immediately alerts `expeditions@sesscouts.org.uk` / admins via email.
3. **Shared Team Route Cards & Revision Loop:**
   * Each Team has one shared Route Card per expedition day (`(team_id, expedition_id, day_number)`). Any active member of that Team can co-edit while status is `draft`.
   * Submitting transitions status to `submitted` and notifies the assigned LiC and Admins. Edits and submissions are blocked once the configured Route Card hard cutoff passes (unless overridden by an Admin).
   * LiC `Request Revisions` appends a timestamped feedback entry to `feedback_history`, transitions status back to editable `draft`, increments `version` on resubmission, and notifies the Team + Parents.
4. **Notification Dispatch Policy (3-Tier Rule):**
   * **Manual Batch Only:** Place Offer notifications (`expedition_place_offer`) are **never** sent automatically; admins must preview recipients/warnings and confirm dispatch.
   * **Event-Driven:** Sign-up confirmations, Place Offer responses (`Accept`/`Query`/`Decline`), team transfers, expedition detail updates, and Route Card `submitted` / `approved` / `revisions_requested` events fire immediately via `Event_Bus`.
   * **WP-Cron Scheduled (Daily):** Evaluates configured communicated deadlines to send missing eDofE / unlinked account chasers, incomplete Tutor LMS / expiring First Aid reminders, and approaching Route Card submission warnings.

---

## 5. UX & Technical Architecture Standards

To eliminate the current tech-stack fragmentation across the plugin[cite: 2]:
* **Unified UI Stack:** Every admin hub and front-end shortcode is built in **React 18 + TypeScript** using shared components (`<TeamReadinessTable />`, `<StatusBadge />`, `<ModuleErrorBoundary />`) and **FontAwesome SVGs** (zero Dashicons, zero Unicode icon entities, zero raw PHP admin forms)[cite: 2, 3, 4].
* **Clean Asset & CSS Boundaries:** Remove phantom `wp-components`, `wp-element`, and `wp-i18n` dependencies from PHP enqueues; split monolithic CSS into scoped design tokens (`ems-tokens.css`), admin layout (`ems-admin.css`), route card engine (`ems-route-card.css`), and isolated front-end portal styles (`ems-portal.css`) so admin styles never leak onto Elementor pages[cite: 2].
* **Anti-Corruption Boundaries:** Third-party plugins (OSM API, Fluent Forms, Tutor LMS) are accessed exclusively through typed adapters in `src/Integrations/`[cite: 4]. Domain modules communicate exclusively via `Event_Bus` and repository interfaces[cite: 2, 4].