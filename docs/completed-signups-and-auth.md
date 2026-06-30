# Completed Signups and Authentication — Spec 1 & Phase 1

This document archives the completed technical specifications and sequencing tasks for **Spec 1: WordPress User Roles & OIDC Mapping** and **Phase 1 — WP User Roles & OIDC Mapping** to maintain a clean project plan for subsequent phases.

---

## Completed Technical Specifications

### [x] Spec 1: WordPress User Roles & OIDC Mapping

#### [x] 1. Custom Roles Registration
EMS registers three custom WordPress roles programmatically on plugin activation (and checks for alignment if they already exist):

| Role Slug | Display Name | Default Capabilities |
|---|---|---|
| `ems_parent` | ESU Parent | `read: true`, `access_ems_parent_portal: true` |
| `ems_explorer` | ESU Explorer | `read: true`, `access_ems_explorer_portal: true` |
| `ems_leader` | ESU Leader | `read: true`, `edit_posts: true` (limited), `access_ems_leader_portal: true` |

* **Implementation Class**: `EMS\Core\Role_Manager` registered under the `init` hook and plugin activation/upgrade hooks.

#### [x] 2. Dynamic OIDC Role Assignment & Relationship Mapping

##### [x] A. Dynamic Hydration Flow (Post-Login & Registration)
1. **Identity Authentication**: The standard OIDC handshake handles initial Google identity login, verifying the email address and returning a temporary `access_token` (gated in the `login-with-google` plugin).
2. **Access Token Interception**: Registered an `http_response` filter to capture the access token from the HTTP request body during the OIDC handshake token exchange.
3. **OSM Hydration Call**: On OIDC login (`rtcamp.google_user_logged_in`) and registration (`rtcamp.google_user_created`), EMS triggers a secondary backend API call using the captured `access_token` to Online Scout Manager's `getDataPayload` endpoint (startup API - `ext/generic/startup/`). This returns the rich OSM context payload (as seen in `mockdata/getDataPayload.json`).
4. **Context Parsing**: The response is processed by `OSM_Parser` to extract the user's roles, section permissions, member access details, and child relationships before discarding the `access_token`.

##### [x] B. How Access Type is Determined
The user's `ems_access_type` is determined by scanning the nested `member_access` block under `$payload['data']['globals']['member_access']`:
* Inside `OSM_Parser::parse_access_type()`, the code iterates over all sections, and then through each member block under `members`.
* It extracts the `access_type` key from the member records (e.g., returns `'member'` for explorers, `'parent'` for parents, or `'local'`/`'leader'` configurations).
* The resolved string is saved in the WordPress user's meta under `ems_access_type`.

##### [x] C. How Parent-Explorer Relationships are Parsed & Stored
Since an individual child explorer may appear under multiple sections in the `member_access` structure (e.g. in `data.globals.member_access.{section_id}.members.{scout_id}` as shown in `mockdata/getDataPayload.json`), the parser deduplicates children and aggregates their sections:
1. **Deduplication Rules**:
   * Scans each section under the `member_access` object.
   * For each member under `members` (keyed by `scout_id`), it filters for rows where `access_type === 'parent'`.
   * Deduplicates by the unique explorer `scout_id`.
   * For duplicate explorer IDs across multiple sections, it merges all unique `section_id`s into a single `section_ids` array.
2. **Metadata Storage**: Saves the resolved child mapping to:
   * **`ems_children`**: A serialized array of deduplicated child objects.
     * Structure: `[ { scout_id: 30001, first_name: "Child", last_name: "One", section_ids: [99001, 99002] }, ... ]`
   * **`ems_scout_ids`**: A simple flat array of unique child IDs: `[30001, 30002]`.
3. **Portal Usage**: The Parent Portal SPA reads the parent's `ems_children` meta to render child selectors, and pre-populates the correct child `scout_id` as a hidden field in the Fluent Form when initiating a signup.

##### [x] D. Role Mapping & Persistence
After resolving the access type and relationships:
1. **Mapping Logic**:
   * If `ems_access_type === 'member'` $\rightarrow$ Add `ems_explorer` role to user; remove default `subscriber` or other non-EMS member roles.
   * If `ems_access_type === 'parent'` $\rightarrow$ Add `ems_parent` role to user; remove other subscriber roles.
   * If `ems_access_type === 'local'` or the user has a non-empty list of administered section IDs in `ems_section_ids` $\rightarrow$ Add `ems_leader` role.
2. **Persistence**: Call `$user->set_role( $target_role )` securely.
3. **Payload Validation**: If critical fields (such as `member_access` or `globals`) are missing from the Online Scout Manager payload, EMS must gracefully log a warning and abort the role assignment rather than disrupting the OIDC login process or throwing hard exceptions.

---

## Completed Sequencing Recommendations & Phases

### [x] Phase 1 — WP User Roles & OIDC Mapping
1. **Behavioral Design (TDD)**: Created Gherkin scenarios in `tests/features/auth-oidc-mapping.feature` defining OIDC role resolution (`ems_parent` deduplication, `ems_leader` section matching) and validation failure conditions.
2. **Implementation**:
   * Registered custom roles (`ems_parent`, `ems_explorer`, `ems_leader`) on plugin activation via `EMS\Core\Role_Manager`.
   * Extended `OIDC_Login_Handler` to assign the target role on successful login and registration based on `ems_access_type`.
3. **Tests**:
   * Implemented `tests/features/auth-oidc-mapping.feature` scenarios using PHPUnit/Brain Monkey stubs to assert roles are correctly assigned on login hooks, metadata is mapped correctly, capabilities are set, and OIDC payloads with missing critical fields log a warning without interrupting the OIDC login process.
