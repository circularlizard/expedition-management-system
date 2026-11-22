# Target Domain Model, Personas & Information Architecture

**Document Status:** Design Baseline (Step 1)  
**Scope:** System boundaries, personas & access rules, target `wp-admin` and front-end Information Architecture (IA), two-tier deadline policy, and UX/engineering standards for `ems-plugin`.

---

## 1. System Boundaries & Master Records

The EMS is a focused operational layer sitting alongside Online Scout Manager (OSM), the Scouts Membership System, Tutor LMS, Fluent Forms, and Elementor. It syncs information from external systems to assist with management, but every data domain has a single master record.

| Domain Concern | System of Record (Master) | EMS Role & Sync Direction |
| :--- | :--- | :--- |
| **Youth PII, medicals, emergency contacts, risk assessments, payments** | **Online Scout Manager (OSM)** | **Read-only cache** (`ems_osm_explorers`) for operational matching & First Aid checks. Never edited in EMS. |
| **Adult Scout membership, PVG/DBS & Scouts Association online training** | **Scouts Membership System** | **Reference only** (recorded on volunteer/leader onboarding; not mastered in EMS). |
| **Sign-up intake (Place & Expedition preferences)** | **Fluent Forms (Forms 6 & 7)** | **Webhook ingestion** into `ems_participant_signups` and `ems_expedition_signups`, followed by OSM reconciliation. |
| **Team membership & Expedition allocation** | **EMS (`ems_teams` / `ems_team_members`)** | **Single Source of Truth.** One-way push of confirmed allocations to OSM events & `'2026 Expeditions'` flexi-record. |
| **Online training delivery & completion** | **Tutor LMS** | **Read-only adapter** (`TutorLmsAdapter`) queried via `ems_scout_ids` for readiness & cron reminders. |
| **Route planning, GPX enrichment & LiC approval** | **EMS (`RouteEngineModule`)** | **Single Source of Truth.** Shared team editing, configurable cutoff enforcement, LiC annotation, and A4 PDF generation. |
| **Public pathway, costs & marketing pages** | **WordPress / Elementor** | External pages (`/expedition-roadmap/`, `/signup/`, `/training/`, `/costs/`). EMS supplies self-contained shortcodes only. |

---

## 2. Canonical Persona & Access Matrix

Eight user personas interact with the EMS (with **Networkers** combining Explorer + Parent capabilities, and adult expedition helpers split strictly between **Expedition Leaders in Charge** and **Expedition Volunteers**). Every REST controller and UI view enforces these boundaries via explicit policy classes (`TeamAccessPolicy`, `RouteCardAccessPolicy`, `UnitScopePolicy`).

| Persona | Auth Mechanism & WP Role | Identity Anchor | Permitted Capabilities | Explicit Restrictions & Redactions |
| :--- | :--- | :--- | :--- | :--- |
| **1. Prospective Participants & Families** | Unauthenticated (`public`) | None | Submit Form 6 / Form 7, submit `[ems-volunteer-signup]`. | Zero read access to rosters, units, or portals. |
| **2. Explorer (Participant)** | OSM OIDC (`ems_explorer`) | `ems_scout_ids` $\rightarrow$ `ems_osm_explorers.scout_id` | View sign-up status; view Practice/Qualifier dates & teammates; **Accept / Query (with message) / Decline** Place Offer (before hard cutoff); track/complete Tutor LMS training & First Aid status; **co-edit & submit shared team Route Cards** (before hard cutoff). | Scoped strictly to own `scout_id` and own `team_id`. Cannot view teammates' medical/ASN notes or edit other teams' routes. |
| **3. Parent / Guardian** | OSM OIDC (`ems_parent`) | `ems_children` / `ems_scout_ids` | Multi-child switcher in `[ems-portal]`; view linked children's sign-ups, training, and expedition dates; **Accept / Query / Decline** Place Offers on child's behalf; view team Route Cards; receive CC on allocation & route emails. | **Cannot complete Tutor LMS training on their child's behalf** (may only complete training under their own personal account). Scoped strictly to linked children (`ems_children`). |
| **4. Networker (Adult Participant)** | OSM OIDC (`ems_explorer` + `ems_parent`) | `ems_scout_ids` + `ems_children` | Combines full **Explorer** capabilities (own expedition, training, route card editing) and **Parent** capabilities (managing any linked children). | Same team and child scoping rules as Explorer + Parent. |
| **5. Unit Leader (DofE Leader)** | Local WP Login (`ems_leader`) | `ems_units.leader_email` / assigned `unit_id` | View sign-up & reconciliation status for Explorers in their Unit; view Teams & Expeditions containing Explorers from their Unit (`[ems-leader-dashboard]`). | **Minimal PII:** medical/ASN details, personal phone numbers, and other units' rosters are stripped server-side. Read-only. |
| **6. Expedition Leader in Charge (LiC)** | OSM OIDC **or** Local WP Login (`ems_leader` + LiC assignment) | `ems_volunteers.id` / `expedition` LiC assignment | Access `[ems-lic-dashboard]` to view assigned expeditions & volunteer roster, audit team readiness (First Aid Rule of Two, training, acceptances), and **inspect, annotate, approve, or request revisions on Route Cards**. | Never enters `wp-admin`. Scoped strictly to expeditions they are assigned as LiC. |
| **7. Expedition Volunteer** | OSM OIDC **or** Local WP Login (`ems_volunteer`) | `ems_volunteers.id` | Register event availability, overnight preferences, and Scout membership/PVG details via `[ems-volunteer-signup]`; view assigned expeditions. | **No route-planning or route-review access.** Cannot access `[ems-lic-dashboard]` route tools or `wp-admin`. |
| **8. EMS Coordinator (Admin)** | OSM OIDC or Local WP Login (`administrator` / `manage_options`) | `wp_users.ID` | Full operational control: Form 6/7 reconciliation, OSM sync & pushback, cross-stage Team Builder, manual Place Offer dispatch, volunteer assignments, email templates, deadlines, backups, and audit logs. | Cannot mutate OSM master PII directly inside EMS. |

---

## 3. Target Information Architecture (IA)

### 3.1 WordPress Admin (`EMS` Top-Level Menu — 7 Domain Hubs)
Consolidates the current 11 fragmented/duplicated admin pages (`ems-explorers`, `ems-reference`, `ems-column-mapper`, `ems-training-report`, etc.) into 7 clean, single-purpose React hubs:

| Menu Position | Menu Title | Slug | Primary Tabs / Views | Absorbs / Retires |
| :--- | :--- | :--- | :--- | :--- |
| **1 (Top)** | **Sign-ups** | `ems` | `Kanban Board` (`To Process`, `On Hold`, `Returned`, `Processed`, `Cancelled`), `All Sign-ups Table`, `Unmatched OSM (scout_id=0)`, `Data Consistency & Chasers` | Replaces flat `submitted\|archived` toggle with full 5-stage Kanban. |
| **2** | **Expeditions & Teams** | `ems-expeditions` | `Events & Calendar`, `Team Builder` (cross-stage continuity + manual Place Offer modal), `Teams & Readiness` (offers, First Aid Rule of Two, Tutor LMS, route status) | Absorbs `ems-training-report` into `Teams & Readiness`. |
| **3** | **Volunteers** | `ems-volunteers` | `Expedition Coverage` (day/overnight staffing & LiC assignment), `Volunteer & PVG/DBS Registry` | Adds missing PVG/DBS & Scout membership UI. |
| **4** | **Units & OSM Sync** | `ems-units-sync` | `Units & DofE Leaders`, `Explorers & Patrols`, `Events & Pushback`, `Flexi-Record Mapper`, `Sync Diagnostics` | Consolidates `ems-units`, `ems-explorers`, `ems-reference`, and hidden `ems-column-mapper`. |
| **5** | **Route Studio** | `ems-route-studio` | `Route Sandbox`, `OS Terrain 50 DEM Manager`, `Route Engine Settings` | — |
| **6** | **Emails & Notifications** | `ems-emails` | `Templates ({{tokens}})`, `12-Month Dispatch Archive`, `Automated Cron & Chaser Rules` | Retires dead `Unit_Leader_Page::render_email_tab()`. |
| **7** | **Settings & System** | `ems-settings` | `OSM Connection`, `Fluent Forms Mapping`, `Deadlines & Cutoffs`, `Access Control & Roles`, `Feature Flags`, `Backups & Parity`, `Audit Logs`, `Help & Shortcodes` | Adds two-tier deadline configuration; retires legacy `ems_save_leader_invite` / `ems_save_chaser_emails` handlers. |

### 3.2 Front-End Shortcodes (Persona Gateways)
All front-end shortcodes render isolated React SPAs wrapped in `<ModuleErrorBoundary />` and `Shortcode_Boundary`.

| Shortcode | Target Persona | Tabs / Capabilities |
| :--- | :--- | :--- |
| **`[ems-portal]`** | Explorers, Networkers & Parents (with multi-child switcher) | 1. **My Sign-ups:** Form 6/7 status & OSM account link indicator.<br>2. **My Expeditions:** Assigned Practice & Qualifier dates, teammate roster, communicated acceptance deadline, and **All-or-Nothing Place Offer Bar** (`Accept`, `Query` with message box, `Decline` — locked after configured hard cutoff).<br>3. **Training:** Award-level Tutor LMS module checklist & First Aid expiry (read-only when viewing a child's profile).<br>4. **Route Planner:** Embedded shared team Route Card editor (DEM profile, Naismith pacing, GPX import/export), `Submit for Review` (enforcing configured hard cutoff), version history, and LiC feedback thread. |
| **`[ems-leader-dashboard]`** | Unit Leaders (`ems_leader`) | 1. **Unit Sign-ups:** Reconciliation & eDofE status for Explorers in the leader's Unit (medical/contact PII redacted).<br>2. **Unit Teams & Expeditions:** Read-only `<TeamReadinessTable />` filtered to teams containing Explorers from the leader's Unit. |
| **`[ems-lic-dashboard]`** | Leaders in Charge (`LiC` assigned to >= 1 event) | 1. **My Expeditions:** Assigned Practice/Qualifier events, joining details & volunteer roster.<br>2. **Team Readiness Audit:** `<TeamReadinessTable />` scoped to the LiC's assigned events.<br>3. **Route Card Review:** Interactive map & Naismith inspection, inline feedback comments, and `Approve` / `Request Revisions` actions. |
| **`[ems-signup-banner]`** | Form Visitors (above Form 6 & 7 in Elementor) | Prompts OIDC login to auto-populate child & unit fields (alias `[ems_signup_banner]` retained for backward compatibility). |
| **`[ems-volunteer-signup]`** | Expedition Volunteers & Prospective LiCs | Availability, overnight preference, and Scout membership/PVG registration wizard + assigned events summary. |

---

## 4. Core Business Rules & Lifecycles

1. **Two-Tier Configurable Deadlines (Zero Hardcoded Dates):**
   * Every time-bound rule separates the **Communicated Deadline** (soft date/offset displayed in portals, emails, and cron reminders) from the **System Hard Cutoff** (enforced by REST endpoints and UI locks), stored in `ems_deadline_policies` and editable under **Settings & System → Deadlines & Cutoffs**.
   * **Place Offer Policy:** Communicated acceptance date (shown in `{{communicated_deadline}}` token and portal) vs. hard portal lock timestamp (blocks `Accept/Query/Decline` mutations unless overridden by an Admin).
   * **Route Card Policy:** Communicated submission offset (`T - N days` before expedition start, triggering cron reminders and displayed as "Due by" in `[ems-portal]`) vs. hard edit/submission lock offset (`T - M days`, enforced in `Route_Card_Controller` unless an Admin grants a per-team extension).
   * **Training & First Aid Policy:** Communicated completion offset (`T - N days`, triggering cron reminders) vs. hard readiness gate offset (`T - M days`).
   * **Sign-up Chaser Policy:** Soft chaser offset after Form 6/7 submission (`+N days`) vs. escalation offset (`+M days`).
2. **All-or-Nothing Place Offers:**
   * A Place Offer covers a Team's combined Practice & Qualifier schedule (with explicit notice if a `date_conflict_pending` override applies).
   * Either the Explorer (or Networker) or a linked Parent can submit a single all-or-nothing response (`Accept`, `Query` with message, or `Decline`) before the configured Place Offer hard cutoff. Submitting `Query` immediately alerts `expeditions@sesscouts.org.uk` / admins via email.
3. **Parent Training Restriction:**
   * Parents viewing a linked child in `[ems-portal]` see the child's Tutor LMS module status as read-only and are never enrolled or credited on the child's behalf.
4. **Shared Team Route Cards & Revision Loop:**
   * Each Team has one shared Route Card per expedition day (`(team_id, expedition_id, day_number)`). Any active member of that Team (and LiCs/Admins) can view it; active Team members can co-edit while status is `draft`.
   * Submitting transitions status to `submitted` and notifies the assigned LiC and Admins. Edits and submissions are blocked once the configured Route Card hard cutoff passes (unless overridden by an Admin).
   * LiC `Request Revisions` appends a timestamped feedback entry to `feedback_history`, transitions status back to editable `draft`, increments `version` on resubmission, and notifies the Team + Parents.
5. **Notification Dispatch Policy (3-Tier Rule):**
   * **Manual Batch Only:** Place Offer notifications (`expedition_place_offer`) are **never** sent automatically; admins must preview recipients/warnings and confirm dispatch.
   * **Event-Driven:** Sign-up confirmations, Place Offer responses (`Accept`/`Query`/`Decline`), team transfers, expedition detail updates, and Route Card `submitted` / `approved` / `revisions_requested` events fire immediately via `Event_Bus`.
   * **WP-Cron Scheduled (Daily):** Evaluates configured communicated deadlines to send missing eDofE / unlinked account chasers, incomplete Tutor LMS / expiring First Aid reminders, and approaching Route Card submission warnings.

---

## 5. UX & Technical Architecture Standards

To eliminate the current tech-stack fragmentation across the plugin:
* **Unified UI Stack:** Every admin hub and front-end shortcode is built in **React 18 + TypeScript** using shared components (`<TeamReadinessTable />`, `<StatusBadge />`, `<ModuleErrorBoundary />`) and **FontAwesome SVGs** (zero Dashicons, zero Unicode icon entities, zero raw PHP admin forms).
* **Clean Asset & CSS Boundaries:** Remove phantom `wp-components`, `wp-element`, and `wp-i18n` dependencies from PHP enqueues; split monolithic CSS into scoped design tokens (`ems-tokens.css`), admin layout (`ems-admin.css`), route card engine (`ems-route-card.css`), and isolated front-end portal styles (`ems-portal.css`) so admin styles never leak onto Elementor pages.
* **Anti-Corruption Boundaries:** Third-party plugins (OSM API, Fluent Forms, Tutor LMS) are accessed exclusively through typed adapters in `src/Integrations/`. Domain modules communicate exclusively via `Event_Bus` and repository interfaces.