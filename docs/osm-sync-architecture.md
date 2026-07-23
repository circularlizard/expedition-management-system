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
