# Milestone 6: Portability, Hardening & Staging Deployment — Implementation Plan

This plan details the implementation steps for Milestone 6 to ensure the plugin can be safely configured, backed up, and deployed to a staging site (hosted on SiteGround) without relying on SSH or WP-CLI access.

---

## 1. Move 2FA to Future Milestone
As requested, Two-Factor Authentication (2FA) is deferred.
*   **Action**: Move 2FA review to Milestone 10/11 in `docs/outstanding-tasks.md`.
*   **Database Encryption**: Standard host database-at-rest encryption is deemed sufficient, as sensitive member contacts are synced dynamically from OSM and can be resynced at any time. No custom PHP column-level encryption will be introduced.

---

## 2. Portability Engine (Unified Backup & Restore)

Since SiteGround does not provide WP-CLI console access, all replication and backup tasks must be executed directly through the WP Admin Dashboard.

### 2.1 Backups Admin UI Tab
*   Create a new **"Backups"** tab on the EMS settings page.
*   Add two main sections:
    1.  **Export Settings & Data**: A button to download a single unified `.json` backup file.
    2.  **Import & Replicate**: A file-upload area to restore a backup file into a clean or existing EMS environment.

### 2.2 Export Payload Structure
The export file will be a single JSON payload structure:
```json
{
  "version": "0.1.x",
  "exported_at": "YYYY-MM-DD HH:MM:SS",
  "options": {
    "ems_managed_sections": {},
    "ems_osm_client_id": "...",
    "ems_flexirecord_column_map": {},
    "ems_fluent_form_id": 12
  },
  "tables": {
    "ems_units": [ ... ],
    "ems_signups": [ ... ],
    "ems_osm_explorers": [ ... ],
    "ems_osm_events": [ ... ]
  }
}
```

### 2.3 Import & Auto-Initialization Engine
When a JSON file is uploaded:
1.  **Safety Check**: Verify file format and version compatibility.
2.  **Table Setup**: Trigger `Table_Installer::install()` to ensure all database tables exist.
3.  **Truncate & Load**: Truncate existing custom tables and insert rows from the backup file in batch SQL transactions.
4.  **Options Restore**: Loop and overwrite the designated `ems_` WordPress options.

---

## 3. Hardening & Log Guarding
*   **Metadata Enrichment Log Toggle**:
    *   Create a new option `ems_debug_log_guard` (checkbox in settings).
    *   Guards all child metadata enrichment logs. When disabled (default), sensitive child details (emails, DOBs) are never output to `error_log` or WP debug files.

---

## 4. Website Integration & Public Shortcode Styling
*   Verify that the unified portal shortcode `[ems-portal]` loads and renders cleanly inside the active theme template of the parent WordPress website.
*   Inject defensive CSS reset rules within the React container elements to avoid stylesheet leakage from parent theme styles (e.g. typography or button padding overrides).

---

## 5. Plugin Header Standardization
*   Standardize header metadata (Plugin Name, Author, Description, Versioning rules) in `ems-plugin.php` to match the sister Google Login plugin configuration.

---

## 6. Staging Deployment
*   Package the compiled assets (`npm run build`) and source code into `dist/ems-plugin-{version}.zip` using `bash bin/package.sh`.
*   Roll out and activate the zip package manually on the Siteground staging website.
