# Target Data Model, State Machines & Contracts

**Document Status:** Technical Specification (Step 2)  
**Companion Baseline:** `01_TARGET_DOMAIN_AND_IA.md`  
**Scope:** Canonical SQL DDL, CPT retirement/migration rules, identity join contracts, state machines, authorization policies, REST APIs, `Event_Bus` hooks, and shared TypeScript DTOs.

---

## 1. Canonical Database Schema (`Table_Installer.php`)

### 1.1 Retired & Consolidated Structures
Because zero teams are assigned in production, the following legacy split-brain structures are retired cleanly during migration:
1. **Retire `team` CPT (`CPT_Registry.php`):** Replaced by `{$wpdb->prefix}ems_teams` + `{$wpdb->prefix}ems_team_members`. `expedition` remains the sole Custom Post Type.
2. **Rename/Replace `ems_cohorts` & `ems_cohort_members`:** Replaced by `{$wpdb->prefix}ems_teams` and `{$wpdb->prefix}ems_team_members` (zero dual Cohort/Team terminology).
3. **Retire `ems_route_submissions` & `dofe_route_cards`:** Merged into a single canonical table `{$wpdb->prefix}ems_route_cards` (registered in `Backup_Manager::DEFAULT_TABLES`).
4. **Retire `wp_options` `ems_chaser_request_history`:** Replaced by `{$wpdb->prefix}ems_notification_logs` for queryable 12-month archiving and deduplication.

### 1.2 New & Canonical SQL Tables

#### Table 1: `{$wpdb->prefix}ems_teams` (Persistent Cross-Stage Team)
```sql
CREATE TABLE {$wpdb->prefix}ems_teams ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(120) NOT NULL, team_code VARCHAR(32) NULL, dofe_level VARCHAR(20) NOT NULL, transport_mode VARCHAR(20) NOT NULL DEFAULT 'foot', practice_expedition_id BIGINT UNSIGNED NULL, qualifier_expedition_id BIGINT UNSIGNED NULL, team_status VARCHAR(32) NOT NULL DEFAULT 'forming', route_cutoff_override_at DATETIME NULL, notes TEXT NULL, created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_level_mode (dofe_level, transport_mode), KEY idx_practice_exp (practice_expedition_id), KEY idx_qualifier_exp (qualifier_expedition_id), KEY idx_team_status (team_status) ) {$charset_collate};

```

#### Table 2: `{$wpdb->prefix}ems_team_members` (Roster, Stage Overrides & All-or-Nothing Offer State)

```sql
CREATE TABLE {$wpdb->prefix}ems_team_members ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, team_id BIGINT UNSIGNED NOT NULL, expedition_signup_id BIGINT UNSIGNED NOT NULL, scout_id BIGINT UNSIGNED NOT NULL DEFAULT 0, practice_status VARCHAR(32) NOT NULL DEFAULT 'assigned', qualifier_status VARCHAR(32) NOT NULL DEFAULT 'assigned', override_expedition_id BIGINT UNSIGNED NULL, availability_override VARCHAR(32) NOT NULL DEFAULT 'matched', notification_status VARCHAR(32) NOT NULL DEFAULT 'draft', notified_at DATETIME NULL, notified_by BIGINT UNSIGNED NULL, offer_status VARCHAR(32) NOT NULL DEFAULT 'pending', offer_query_message TEXT NULL, offer_responded_at DATETIME NULL, offer_responded_by BIGINT UNSIGNED NULL, added_by BIGINT UNSIGNED NOT NULL, added_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uq_team_exp_signup (team_id, expedition_signup_id), KEY idx_exp_signup (expedition_signup_id), KEY idx_scout_id (scout_id), KEY idx_offer_status (offer_status), KEY idx_notification_status (notification_status) ) {$charset_collate};

```

*Column semantics:*

* `practice_status` / `qualifier_status`: `'assigned' | 'split_alternate' | 'removed'`.
* `override_expedition_id`: Non-null only when `practice_status = 'split_alternate'` or `qualifier_status = 'split_alternate'`, pointing to the guest `expedition` post ID for that stage.
* `availability_override`: `'matched' | 'date_conflict_pending' | 'split_date'`.
* `offer_status`: `'pending' | 'accepted' | 'queried' | 'declined'` (all-or-nothing across the Team's offer).

#### Table 3: `{$wpdb->prefix}ems_route_cards` (Unified Shared Team Route Card)

```sql
CREATE TABLE {$wpdb->prefix}ems_route_cards ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, team_id BIGINT UNSIGNED NOT NULL, expedition_id BIGINT UNSIGNED NOT NULL, day_number TINYINT UNSIGNED NOT NULL DEFAULT 1, version INT UNSIGNED NOT NULL DEFAULT 1, route_title VARCHAR(200) NOT NULL DEFAULT '', header_data LONGTEXT NULL, route_data LONGTEXT NULL, gpx_raw LONGTEXT NULL, status VARCHAR(32) NOT NULL DEFAULT 'draft', submitted_by BIGINT UNSIGNED NULL, submitted_at DATETIME NULL, reviewed_by BIGINT UNSIGNED NULL, reviewed_at DATETIME NULL,
    latest_feedback TEXT NULL,
    feedback_history LONGTEXT NULL,
    pdf_file_path VARCHAR(255) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_team_exp_day (team_id, expedition_id, day_number),
    KEY idx_expedition_status (expedition_id, status),
    KEY idx_team_id (team_id)
) {$charset_collate};

```

*`feedback_history` JSON array item shape:* `[{ "version": 1, "action": "revisions_requested" | "approved", "comment": "...", "author_user_id": 12, "author_name": "...", "created_at": "2026-05-01T19:20:00Z" }]`.

#### Table 4: `{$wpdb->prefix}ems_notification_logs` (12-Month Dispatch Archive & Cron Deduplication)

```sql
CREATE TABLE {$wpdb->prefix}ems_notification_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_slug VARCHAR(64) NOT NULL,
    trigger_type VARCHAR(24) NOT NULL,
    recipient_email VARCHAR(190) NOT NULL,
    cc_emails TEXT NULL,
    subject VARCHAR(255) NOT NULL,
    target_scout_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    target_team_id BIGINT UNSIGNED NULL,
    target_expedition_id BIGINT UNSIGNED NULL,
    dedupe_key VARCHAR(128) NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'sent',
    error_message TEXT NULL,
    triggered_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sent_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sent_at (sent_at),
    KEY idx_dedupe_key (dedupe_key),
    KEY idx_target_scout (target_scout_id),
    KEY idx_template (template_slug)
) {$charset_collate};

```

*(Purged automatically beyond configurable retention window, default 365 days, by `Log_Rotator`.)*

#### Existing Tables Retained (with Additive Updates)

* `ems_participant_signups`: Update `signup_status` enum/values to `'to_process' | 'on_hold' | 'returned' | 'processed' | 'cancelled'`.
* `ems_expedition_signups`: Update `signup_status` values to `'to_process' | 'on_hold' | 'returned' | 'processed' | 'cancelled'`.
* `ems_osm_explorers`, `ems_osm_events`, `ems_osm_event_attendance`, `ems_units`, `ems_unit_patrols`, `ems_volunteers`, `ems_volunteer_availability`, `ems_audit_logs`: Retained as-is (`ems_volunteers.dbs_number` also stores PVG/membership ref).

### 1.3 `expedition` CPT Meta Schema (`CPT_Registry.php`)

All `post_meta` keys on `expedition` must be explicitly registered in `CPT_Registry`:

* `_ems_stage`: `'training' | 'practice' | 'qualifier'`
* `_ems_dofe_level`: `'bronze' | 'silver' | 'gold'`
* `_ems_transport_mode`: `'foot' | 'cycle' | 'paddle'`
* `_ems_start_date` / `_ems_end_date`: `YYYY-MM-DD`
* `_ems_location`: string
* `_ems_lic_user_id`: `int` (WP User ID of Leader in Charge)
* `_ems_lic_volunteer_id`: `int` (`ems_volunteers.id` of Leader in Charge)
* `_ems_volunteer_requirements`: JSON (`{ "min_leaders": int, "min_first_aiders": int, "notes": string }`)
* `_ems_route_parameters`: JSON (`{ "days": int, "min_hours_per_day": float, "area_bounds": string, "notes": string }`)
* `_ems_training_requirements`: JSON array of Tutor LMS course IDs
* `_ems_osm_event_id` / `_ems_osm_event_url`: string

### 1.4 Two-Tier Deadline Option Schema (`wp_options`: `ems_deadline_policies`)

Zero dates or day offsets may be hardcoded in PHP, TypeScript, or email templates. `DeadlinePolicyService` reads `get_option('ems_deadline_policies')`:

```json
{
  "place_offer": {
    "communicated_date": "2026-12-15",
    "hard_cutoff_datetime": "2026-12-31 23:59:59"
  },
  "route_card": {
    "communicated_days_before": 21,
    "hard_cutoff_days_before": 14,
    "reminder_cadence_days": [21, 16]
  },
  "training": {
    "communicated_days_before": 28,
    "hard_readiness_days_before": 7,
    "reminder_cadence_days": [28, 14]
  },
  "signup_chaser": {
    "soft_chaser_days_after": 7,
    "escalation_days_after": 21
  }
}

```

---

## 2. Canonical Identity & Join Rules

1. **Roster & Sign-up Join Rule:**
* Every Team membership row anchors on `(team_id, expedition_signup_id)` plus `scout_id` (`0` when unmatched in OSM).
* Joins to `ems_osm_explorers` **must** guard against `scout_id = 0`:
`LEFT JOIN {$prefix}ems_osm_explorers e ON (tm.scout_id > 0 AND tm.scout_id = e.scout_id)`.
* When an admin reconciles a sign-up (`scout_id: 0 -> N`), `SignupReconciliationService` atomically updates `scout_id` across `ems_participant_signups`, `ems_expedition_signups`, and `ems_team_members` in one transaction.


2. **WordPress User ↔ Explorer Resolution (`ExplorerUserResolver`):**
* Retires all legacy singular `ems_scout_id` queries in `TutorLmsAdapter`, `TeamReadinessService`, and `Team_Member_Repository`.
* Standardises on `ems_scout_ids` (JSON array of integers on `wp_usermeta`, populated by `OIDC_Login_Handler`) and `ems_children` (for Parents/Networkers):
* `ExplorerUserResolver::getScoutIdsForUser(int $userId): int[]` (self `ems_scout_ids`)
* `ExplorerUserResolver::getManagedChildScoutIds(int $userId): int[]` (parent `ems_children`)
* `ExplorerUserResolver::findWpUserIdByScoutId(int $scoutId): ?int` (for Tutor LMS queries)


* **Parent Training Guard:** `TutorLmsAdapter::getChildTrainingProgress(int $parentUserId, int $childScoutId)` resolves the child's own `wp_user_id` via `findWpUserIdByScoutId($childScoutId)`—never querying the parent's `wp_user_id`.



---

## 3. Entity Lifecycles & State Machines

### 3.1 Sign-up Kanban (`ems_participant_signups` & `ems_expedition_signups`)

* **States:** `to_process` (initial on Form 6/7 webhook) $\rightarrow$ `on_hold` | `returned` | `processed` | `cancelled`.
* **Transitions:**
* `to_process -> processed`: Requires `scout_id > 0` (matched to OSM) and `dofe_number` verified (or waived). Fires `ems.signup.processed`.
* `to_process -> on_hold | returned`: Missing OSM share, missing eDofE, or unvalidated ASN. Fires `ems.signup.status_changed`.



### 3.2 Team & All-or-Nothing Place Offer (`ems_teams` & `ems_team_members`)

* **Team Status (`ems_teams.team_status`):** `forming` $\rightarrow$ `partially_scheduled` (one stage set) $\rightarrow$ `scheduled` (Practice + Qualifier set) $\rightarrow$ `archived`.
* **Notification Status (`ems_team_members.notification_status`):**
* `draft` (default on add; hidden from `[ems-portal]`)
* `notified` (set exclusively via `ExpeditionOfferDispatcher::dispatchBatch()`; visible in `[ems-portal]`)
* `re_notify_needed` (set automatically if a `notified` member's team or expedition date changes)


* **Offer Response Status (`ems_team_members.offer_status` — All-or-Nothing):**
* `pending` $\rightarrow$ `accepted` | `queried` (requires non-empty `offer_query_message`) | `declined`.
* **Guard:** Blocked if `DeadlinePolicyService::isPlaceOfferHardCutoffPassed()` is `true` (unless user has `manage_options`).
* **Side Effects:**
* `accepted`: Fires `ems.offer.responded` (notifies Admin; enables OSM event push).
* `queried`: Fires `ems.offer.responded` (immediately emails `expeditions@sesscouts.org.uk` / Admins with `offer_query_message`).
* `declined`: Fires `ems.offer.responded` (notifies Admin; flags slot open in Team Builder).





### 3.3 Shared Team Route Card (`ems_route_cards`)

* **States:** `draft` $\rightarrow$ `submitted` $\rightarrow$ `approved` | (back to `draft` via `revisions_requested`).
* **Transitions & Guards:**
* `save_draft` (`draft -> draft`): Allowed for active members of `team_id` (and LiC/Admin). Blocked for Explorers if `DeadlinePolicyService::isRouteCardHardCutoffPassed($expeditionId, $teamId)` is `true`.
* `submit` (`draft -> submitted`): Allowed for active members of `team_id` before hard cutoff. Stamps `submitted_by`, `submitted_at`, and fires `ems.route_card.submitted` (emails assigned LiC + Admins).
* `review: approve` (`submitted -> approved`): Allowed for assigned LiC or Admin. Appends to `feedback_history`, stamps `reviewed_by`, `reviewed_at`, locks editing, and fires `ems.route_card.reviewed`.
* `review: request_revisions` (`submitted -> draft`): Allowed for assigned LiC or Admin. Requires `comment`, appends to `feedback_history`, sets `latest_feedback`, increments `version`, unlocks editing for the team, and fires `ems.route_card.reviewed`.



---

## 4. Authorization Policy Contracts

All REST endpoints replace generic `is_user_logged_in()` checks with explicit policy classes in `src/Core/Security/`:

1. **`TeamAccessPolicy`:**
* `canViewTeam(int $userId, int $teamId): bool` — `true` if Admin, LiC of team's Practice/Qualifier expedition, Unit Leader of a unit with >= 1 member in `team_id` (redacted DTO), active member of `team_id`, or Parent of an active member of `team_id`.
* `canRespondToOffer(int $userId, int $teamMemberId): bool` — `true` if Admin, or `userId` resolves to that member's `scout_id` (Explorer/Networker) or a linked Parent (`ems_children`), AND `!isPlaceOfferHardCutoffPassed()`.


2. **`RouteCardAccessPolicy` (Fixes P0 REST Vulnerability):**
* `canRead(int $userId, int $teamId, int $expeditionId): bool` — Admin, LiC of `$expeditionId`, active member of `$teamId`, or Parent of an active member of `$teamId`. (General Volunteers without LiC assignment return `false`.)
* `canEditOrSubmit(int $userId, int $teamId, int $expeditionId): bool` — Admin, or (active member of `$teamId` AND card `status === 'draft'` AND `!isRouteCardHardCutoffPassed($expeditionId, $teamId)`).
* `canReview(int $userId, int $expeditionId): bool` — Admin, or user assigned as LiC on `$expeditionId`.


3. **`UnitScopePolicy`:**
* Strips `parent_email`, `p1_email*`, `p2_email*`, `phone`, `additional_support_needs`, and medical fields from all `[ems-leader-dashboard]` responses.



---

## 5. Target REST API & `Event_Bus` Contracts

### 5.1 REST Endpoints (`/wp-json/ems/v1` & `/wp-json/ems-route/v1`)

All routes require `X-WP-Nonce` verification (`rest_cookie_check_errors`).

| Namespace & Route | Methods | Policy / Capability | Purpose |
| --- | --- | --- | --- |
| `ems/v1/signups` & `/signups/{id}/status` | `GET`, `PATCH` | `manage_options` | Kanban board transitions & reconciliation |
| `ems/v1/teams` & `/teams/{id}` | `GET`, `POST`, `PATCH`, `DELETE` | `manage_options` | Cross-stage Team CRUD |
| `ems/v1/teams/{id}/members` | `POST`, `DELETE` | `manage_options` | Add/remove member (`expedition_signup_id`) |
| `ems/v1/teams/{id}/schedule` | `POST` | `manage_options` | Assign Practice & Qualifier events + `member_resolutions` |
| `ems/v1/teams/notifications/preview` & `/dispatch` | `POST` | `manage_options` | Dry-run preview & confirmed manual batch dispatch |
| `ems/v1/teams/readiness` | `GET` | `manage_options` / `LiC` / `UnitLeader` | Scoped readiness matrix (First Aid Rule of 2, Tutor LMS, offers, routes) |
| `ems/v1/portal/me` | `GET` | `is_user_logged_in` | Explorer / Parent / Networker profile, child switcher & deadline policies |
| `ems/v1/portal/offers/{memberId}/respond` | `POST` | `TeamAccessPolicy::canRespondToOffer` | Submit all-or-nothing `accepted | queried | declined` + `query_message` |
| `ems/v1/leader-dashboard/overview` | `GET` | `access_ems_leader_portal` | Unit sign-ups + unit-scoped `<TeamReadinessTable />` (PII redacted) |
| `ems/v1/lic-dashboard/overview` | `GET` | `LiC` assignment | Assigned expeditions, volunteer roster, readiness & route queue |
| `ems/v1/settings/deadlines` | `GET`, `POST` | `manage_options` | Read/update `ems_deadline_policies` |
| `ems/v1/notifications/logs` | `GET` | `manage_options` | Query 12-month `ems_notification_logs` archive |
| `ems-route/v1/card/{expId}/{teamId}/{day}` | `GET` | `RouteCardAccessPolicy::canRead` | Load shared team Route Card + `EmsRouteBridgeAdapter` context |
| `ems-route/v1/card/{id}/save` & `/submit` | `POST` | `RouteCardAccessPolicy::canEditOrSubmit` | Save draft or submit Route Card (enforces hard cutoff) |
| `ems-route/v1/card/{id}/review` | `POST` | `RouteCardAccessPolicy::canReview` | LiC `approve` or `request_revisions` (persists `feedback_history`) |

### 5.2 Domain Events (`Event_Bus`) & Daily Cron

* `ems.signup.received` $\rightarrow$ Sends sign-up confirmation to Parent/Explorer + DofE Leader + Admin alert if `scout_id = 0`.
* `ems.expedition.offers_dispatched` $\rightarrow$ Logs batch dispatch to `ems_audit_logs` & `ems_notification_logs`.
* `ems.offer.responded` $\rightarrow$ Emails Admins/shared mailbox on `accepted`, `queried` (including `offer_query_message`), or `declined`.
* `ems.team.member_transferred` $\rightarrow$ Marks `re_notify_needed` and notifies Unit Leader / Admin.
* `ems.expedition.updated` $\rightarrow$ Notifies assigned Explorers/Parents when LiC, route params, or joining info changes.
* `ems.route_card.submitted` $\rightarrow$ Emails assigned LiC and Admins.
* `ems.route_card.reviewed` $\rightarrow$ Emails Team Explorers and Parents (`approved` or `revisions_requested` with LiC comment).
* **Daily Cron (`ems_daily_deadline_check`):** Checks `DeadlinePolicyService` communicated deadlines and dispatches deduplicated reminders (via `ems_notification_logs.dedupe_key`) for: (a) missing eDofE / unlinked OSM accounts, (b) incomplete Tutor LMS / expiring First Aid, and (c) approaching Route Card submission deadlines.

---

## 6. Shared TypeScript Contracts (`packages/contracts/src/index.ts`)

```ts
export type OfferStatus = 'pending' | 'accepted' | 'queried' | 'declined';
export type NotificationStatus = 'draft' | 'notified' | 're_notify_needed';
export type RouteCardStatus = 'draft' | 'submitted' | 'approved';
export type SignupKanbanStatus = 'to_process' | 'on_hold' | 'returned' | 'processed' | 'cancelled';

export interface DeadlinePolicyDTO {
  place_offer: { communicated_date: string; hard_cutoff_datetime: string };
  route_card: { communicated_days_before: number; hard_cutoff_days_before: number; reminder_cadence_days: number[] };
  training: { communicated_days_before: number; hard_readiness_days_before: number; reminder_cadence_days: number[] };
  signup_chaser: { soft_chaser_days_after: number; escalation_days_after: number };
}

export interface RouteCardFeedbackEntry {
  version: number;
  action: 'approved' | 'revisions_requested';
  comment: string;
  author_user_id: number;
  author_name: string;
  created_at: string;
}

export interface OfferResponsePayload {
  response: 'accepted' | 'queried' | 'declined';
  query_message?: string;
}

```

```

---

### Ready for Step 3 (`03_IMPLEMENTATION_PLAN.md`)?

Once you've reviewed `02_TARGET_DATA_MODEL_AND_CONTRACTS.md` above, say **"proceed to step 3"** and I will produce **`docs/03_IMPLEMENTATION_PLAN.md`** — the dependency-ordered execution plan with copy-paste Antigravity builder and independent auditor prompts for every slice.

```