# OSM Explorer Synchronization & Roster Architecture

This document describes how the Expedition Management System (EMS) coordinates data synchronization with Online Scout Manager (OSM), specifically regarding explorer records, database schemas, and OIDC login linking rules.

---

## 1. The `ems_osm_explorers` Database Schema

The master roster of synchronized explorers is managed in the prefix-bound table `ems_osm_explorers`.

| Column | Type | Default | Description |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | *Auto Increment* | Local primary key. |
| `scout_id` | `BIGINT UNSIGNED` | | **Identity Anchor**: The unique OSM member ID. All relationships (e.g. team memberships) key off this ID. |
| `wp_user_id` | `BIGINT UNSIGNED` | `NULL` | Links to the WordPress user ID if logged in/linked via OIDC. |
| `section_id` | `BIGINT UNSIGNED` | | The active OSM Section ID where the member is enrolled. Used for targeting writebacks. |
| `first_name` | `VARCHAR(100)` | `''` | Syncs from OSM. |
| `last_name` | `VARCHAR(100)` | `''` | Syncs from OSM. |
| `email` | `VARCHAR(100)` | `''` | Explorer's individual contact email. |
| `parent_email` | `VARCHAR(100)` | `''` | Parent/guardian contact email. |
| `patrol` | `VARCHAR(100)` | `''` | Unit patrol assignment from OSM. |
| `first_aid_level` | `VARCHAR(30)` | `'none'` | Tracks local certification (`none`, `first_response`, `full_first_aid`). |
| `dofe_number` | `VARCHAR(50)` | `NULL` | Local Duke of Edinburgh Award registration number. |
| `additional_support_needs` | `TEXT` | `NULL` | Local private medical/access support notes. |
| `last_local_update_at` | `DATETIME` | `NULL` | Timestamp of last local database change. |
| `last_ems_push_at` | `DATETIME` | `NULL` | Timestamp of last successful API push to OSM. |
| `synced_at` | `DATETIME` | | Timestamp of the last pull from OSM. |

---

## 2. Sync Logic and Write Targets

Roster writes to the `ems_osm_explorers` table occur in three areas:

1. **Roster Pull (`OSM_Section_Importer.php`)**: Processes basic demographic attributes (`scout_id`, `section_id`, `first_name`, `last_name`, `email`, `parent_email`, `patrol`, `synced_at`).
2. **Deep Reference Sync (`OSM_Reference_Sync.php`)**: Administrative full sync. Pulls demographics and explicitly queries the individual details API from OSM for email updates.
3. **Repository Methods (`OSM_Explorer_Repository.php`)**:
   * `link_wp_user_by_email`: Links a WP User ID to corresponding explorer rows matching their email.
   * `update_first_aid_level`: Edits local first-aid certifications.
   * `touch_last_local_update`: Touches the modified timestamp during local edits.

---

## 3. How the Sync Handles Existing Records (Preservation Rules)

To prevent losing local database states (such as active WordPress user links and private medical support logs), the sync utilizes a **non-destructive check** instead of SQL `REPLACE INTO`:

```php
$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE scout_id = %d", $scout_id ) );
if ( $exists ) {
    // Selectively update only the fields retrieved from OSM
    $wpdb->update( ... );
} else {
    // Insert new record
    $wpdb->insert( ... );
}
```

### Column Treatment Table
* **Overwritten (Synced from OSM)**: `section_id`, `first_name`, `last_name`, `email`, `parent_email`, `patrol`, `synced_at`.
* **Preserved (Kept Intact)**: `wp_user_id`, `first_aid_level`, `dofe_number`, `additional_support_needs`, `last_local_update_at`.

---

## 4. OIDC Login & Parent-Child Authentication Mapping

For parent-child relationships, the login flow is **decoupled** from the `ems_osm_explorers` table. This prevents new signups or un-synced child records from blocking parent authentication.

1. **OSM OIDC Payload**: When a parent logs in, the OSM authorization endpoint returns the parent's live child mappings (`scout_id`s and their sections) directly.
2. **Managed Unit Filtering**: The plugin queries active units in the local `ems_units` table (`active = 1`).
3. **Dynamic Intersection Mapping**: The parent is dynamically linked in WordPress User Meta (`ems_children`) to any child in the OIDC payload who belongs to a managed section. The parent-child linkage is fully operational even if the child does not yet exist in the `ems_osm_explorers` table.

---

## 5. WordPress User Role Resolution

WordPress user roles are programmatically registered on plugin activation and dynamically mapped upon successful OIDC login based on the returned access type and section administrations:

| OSM Access Type (`ems_access_type`) | Criteria | Resolved WordPress Role | Role Description |
|---|---|---|---|
| `'member'` | User logs in as themselves. | `ems_explorer` | Explorer access to view their own teams, training, and routes. |
| `'parent'` | User logs in as a parent of active children. | `ems_parent` | Parent access to manage signups and medical forms for linked children. |
| `'local'` / Administration | User administers one or more OSM sections (`ems_section_ids` is not empty). | `ems_leader` | Leader access to manage events, teams, sync operations, and approve routes. |

The mapping execution lives in `OIDC_Login_Handler::assign_user_role()`. The roles are evaluated and set on every login request to ensure any changes in their OSM administrative privileges (e.g. promoting a leader or aging out an explorer) are reflected immediately.

---

## 6. How an Explorer's `scout_id` is Determined

The `scout_id` acts as the primary identity key across the system. It is extracted and resolved through three main channels:

### A. Online Scout Manager API Payloads (Member Roster)
* When retrieving participants from OSM, the API returns list objects where each member is represented with a unique numeric ID (`member_id` or `scoutid`).
* During roster sync (`OSM_Section_Importer`), the system extracts this value directly:
  ```php
  $scout_id = (int) ( $member['member_id'] ?? 0 );
  ```
  This is saved directly to `ems_osm_explorers.scout_id`.

### B. OIDC Login Parsing (Parent Mappings)
* During parent OIDC authentication, the returned profile payload contains access properties nested inside the `member_access` globals block.
* The system uses `OSM_Parser::parse_scout_ids()` to loop over the keys of the `members` array in this payload to identify child mappings:
  ```php
  foreach ( $payload['data']['globals']['member_access'] ?? array() as $section_data ) {
      foreach ( array_keys( $section_data['members'] ?? array() ) as $scout_id ) {
          $ids[ (int) $scout_id ] = true;
      }
  }
  ```
  This array of mapped IDs is serialized and saved to WordPress User Meta (`ems_scout_ids`).

### C. Fluent Forms Submissions (Signup Mapping)
* When a parent opens a registration/signup form, the child selector dropdown is dynamically populated by the system using active OIDC child metadata.
* Each option is structured in a pipes-delimited string format:
  ```
  value="[scout_id]|[first_name]|[last_name]"
  ```
  *Example:* `value="30001|Alex|Smith"`
* Upon submission, `Fluent_Forms_Sync::parse_name_and_scout_id()` splits this string and extracts the first element as the integer `scout_id` to store in `ems_signups.scout_id`. Alternatively, it reads a custom hidden field `signup_scoutid`.

