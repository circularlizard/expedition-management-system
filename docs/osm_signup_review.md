# Architectural Specification: Optional OIDC Signup Flow (Hybrid Model)

This document outlines the architectural specification and implementation plan for the **Optional OIDC Signup Flow (Hybrid Model)** for Duke of Edinburgh (DofE) expedition signups. Parents can choose to log in via Online Scout Manager (OSM) to auto-fill their child's details, or submit as a guest (validated via Double Opt-in).

---

## 1. Executive Summary & Design Decisions

### The Core Challenge
The mandatory OSM OIDC login for parents created high UX friction, database user bloat, and rigid form integration. Because parent-level OSM credentials cannot query child contact details (emails or Date of Birth) directly from the OSM API, the OIDC login served only to capture the parent's email and the child's `scout_id` and name.

### Decided Architecture: Optional OIDC Hybrid Flow (Alternative C)
1. **Optional Login:** Parents can submit signup forms as a **Guest** (no login required) or **Log in via OSM** using a banner at the top of the form.
2. **Simplified Child Payload (No Email/DOB Retrieval):** We will completely remove child email and DOB lookup calls from the `OIDC_Login_Handler` API synchronization. DOB and Explorer Email fields on the forms will **always be read-write** and manually entered by the parent.
3. **Dropdown Stays Visible:** The child dropdown remains visible for both logged-in parents and guest parents (it is simply empty/placeholder-only for guests).
4. **Verified `scout_id` (Logged-In):** Logged-in parents select a child, which auto-fills the child's name and ESU unit, and submits the entry with a pre-validated `scout_id`. Double Opt-in is bypassed.
5. **Double Opt-In (Guests):** Guest parents enter all details manually. On submit, Fluent Forms triggers a **Double Opt-in** confirmation email to verify their address before the signup is active on the Admin Signups Board. The admin reconciles guest signups to synced OSM records using heuristic matching.

---

## 2. Client-Side Form Interaction & Mapping

The client-side JavaScript handles auto-population dynamically:

* **Guest Mode (Logged Out):**
  - The child dropdown is empty. Parents skip it and type all details (First Name, Last Name, DOB, Explorer Email, Unit dropdown choice) manually.
  - Email and DOB inputs are fully editable.
* **OIDC Mode (Logged In):**
  - The dropdown options contain the child's name as the label and the pure integer **`scout_id`** as the value (e.g. `<option value="30001">Mary Smith</option>`). The legacy pipe-separated `30001|Mary|Smith` string format is deprecated.
  - When the parent selects a child, the client-side JS looks up the `scout_id` in the localized child mapping array and auto-populates the First Name, Last Name, and ESU Unit dropdown choice.
  - DOB and Explorer Email fields remain blank and **read-write**, allowing the parent to type them manually since they are not retrieved from OSM.

---

## 3. System Implications

### 3.1 Page Protection settings (`ems_protected_pages`)
* **Signup Pages:** Signup pages must be **removed from the `ems_protected_pages` option** in the EMS settings page. If they remain on the list, WordPress will intercept request routing and force OIDC login anyway.
* **Other Portals:** The Leader Dashboard, Explorer Portal, Tutor LMS dashboard, and route card submission pages remain strictly protected under OIDC role restrictions.

### 3.2 Parent Portal & Route Card Access (Authorization Bound)
* **The Concern:** How do guest-submitting parents access their child's team route cards and feedback later?
* **The Solution:** The Parent Portal access control check does **not** query signup database records. Instead, when a parent logs in via OIDC to view the portal, their identity payload lists their children's `scout_ids` in their user meta. The portal queries this list to authorize them. Thus, signing up as a guest does not block future portal access.

### 3.3 WordPress User DB Cleanup
* Parents submitting as guests will not have WordPress user accounts created (`parent_user_id = 0` in the database). This prevents database user bloat.
* Parent accounts will only be created if they actively choose to log in.
* Backend code referencing `parent_user_id` handles `0` or null gracefully, relying on the `parent_email` string column instead.

### 3.4 Double Opt-in Configuration
* Guest parent email addresses are verified using Fluent Forms' native Double Opt-in feature, sending a confirmation link that must be clicked before the signup is approved. This requires SiteGround SMTP (or similar) mail delivery to be configured before signup pages go live.

---

## 4. Implementation Plan

### Step 1: Design a Custom Decoupled Shortcode (`[ems_signup_banner]`)
The custom shortcode will render only the banner/login controls and configure the form mappings, allowing the form itself to be rendered by Elementor's native widget.

Example usage placed on the same page as the form:
`[ems_signup_banner form_id="6" type="participant" scout_field="signup_child" unit_field="signup_unit"]`

**Shortcode Logic (`src/Admin/Portal_Controller.php` or similar):**
1. **Optional Login Banner:** If `!is_user_logged_in()`, render a clean HTML banner at the location of the shortcode:
   ```html
   <div class="ems-login-banner ems-card ems-p-16 ems-flex-between ems-align-center">
       <div>
           <h4 class="ems-m-0">Speed up your DofE registration</h4>
           <p class="ems-meta-text ems-m-0 ems-small-text">Log in with Online Scout Manager to auto-fill your child's details and skip email confirmation.</p>
       </div>
       <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="button button-primary">Log in via OSM</a>
   </div>
   ```
2. **Dynamic Mapping Configuration Store:** 
   The shortcode handler dynamically updates the database option for the given form ID whenever the page is rendered:
   ```php
   update_option( "ems_form_mappings_{$form_id}", [
       'type'        => $type,
       'scout_field' => $scout_field,
       'unit_field'  => $unit_field,
   ] );
   ```
   This ensures that the validation and submission hooks (which trigger via separate POST requests) can always fetch the active field names for the form ID using `get_option("ems_form_mappings_{$form_id}")`.
3. **Enqueue JS Mapping Configuration:** Pass the shortcode attributes and the resolved child metadata array (containing ONLY child names, ESU unit codes, and scout IDs) to the client-side script using `wp_localize_script()`.

### Step 2: Fluent Forms Layout & Double Opt-In
1. **Double Opt-In Configuration:**
   - Enable the Double Opt-In settings in Fluent Forms.
   - Configure the integration rule to bypass/disable verification when `User Status is Logged In`.

### Step 3: Simplify PHP Login Handlers & Dropdown Values (`OIDC_Login_Handler.php`)
1. **Remove Child API Calls:**
   - Completely delete the retrieval logic calling `get_member_detail` and `get_individual` inside `enrich_children()`. 
   - Store only the child's `scout_id`, first name, last name, and unit code retrieved from the OIDC startup payload `member_access` block directly into the parent user meta `ems_children`.
2. **Update Form Handlers (`Fluent_Forms_Sync.php`):**
   - **Simplify Dropdown Values:** Update `populate_child_dropdown` so the option value is simply `$scout_id` instead of `$scout_id|$first_name|$last_name`.
   - **Deprecate Pipe-Splitting Logic:** Update `validate_submission` and `parse_name_and_scout_id` to read the `scout_id` directly as an integer. Remove the legacy `explode('|', $submitted_child)` parsing logic entirely.
   - Modify `validate_submission` so that the ownership validation on `signup_child` only runs if `get_current_user_id() !== 0`.
   - Update `parse_name_and_scout_id` to fallback to manual first/last name fields if `signup_child` select value is empty (guest mode).

### Step 4: Server-side Heuristic Matcher (`Signup_Repository.php`)
1. **Develop Matching Engine:**
   - Create a helper method `find_suggested_explorer(array $signup_row)` that queries `ems_osm_explorers` table:
     - Check 1: Match `first_name`, `last_name` AND `email`.
     - Check 2: Match `first_name`, `last_name` AND `dob`.
2. **Extend REST Responses:**
   - Update REST endpoints (`get_participant_signups` and `get_expedition_signups`) to execute the helper for any row where `scout_id` is missing/0. Return the `suggested_scout_id`, `suggested_name`, and `suggested_unit` in the JSON payload.

### Step 5: Admin Board Reconciliation UI (`SignupsBoard.tsx` & REST API)
1. **Register Linking Endpoint:**
   - Add a POST endpoint `ems/v1/signups/link` that accepts a `signup_id`, `type` (`participant`|`expedition`), and `scout_id`. It updates the signup table with the linked `scout_id`.
2. **Upgrade Inspector UI:**
   - In the Inspector panel of the React board, check if the selected signup is unmatched (`is_synced_osm === 0`):
     - **If a Heuristic Match is present:** Render a banner: *"Fuzzy Match Found: [Suggested Name] ([Suggested Unit])"* alongside a **"Link Explorer"** button. Clicking this hits the linking endpoint and refetches the list.
     - **Manual Search Fallback:** Provide an autocomplete select box populated with the list of synced explorers. Allow the admin to search by name/unit and click **"Manual Link"** to connect them.

### Step 6: Client-Side Helpers
1. **Mailcheck.js Integration:**
   - Enqueue a lightweight client-side email checker on the email fields of the form to flag typical parent email typos dynamically as they type.

---

## 7. Sense Check & Risk Mitigation Analysis

The following operational and technical edge cases must be addressed during implementation:

### 7.1 Payment Integration & Double Opt-In State Conflicts
* **The Risk:** If a form requires a Stripe payment, Fluent Forms initially saves the submission. If Double Opt-In is enabled, Fluent Forms sets the submission status to `pending` (unverified) until the email link is clicked. If a parent pays but forgets to verify their email, their registration could be lost or hidden from the Admin Board, causing payment-vs-status mismatches.
* **Mitigation:** 
  1. Ensure the Stripe transaction completes successfully regardless of the double opt-in verification link status.
  2. The Admin Signups Board REST API must explicitly **exclude** signups with a Fluent Forms status of `pending` (unverified), but flag a notice or section for *"Paid, Unverified Entries"* so admins can manually follow up if a payment was captured without the opt-in verification link being clicked.

### 7.2 Sync Order & New Recruits (No Synced OSM Record)
* **The Risk:** Under Alternative C, guest signups map to `scout_id = 0`. During post-deadline syncs, new recruits (e.g. young people signing up for Bronze who are not yet added to any scout section in Online Scout Manager) will not have any record in `ems_osm_explorers` to match against.
* **Mitigation:**
  1. The Heuristic Reconciliation Board will show these as `"No Match Found (New Recruit?)"`.
  2. **Process Workflow:** The Admin must first create the explorer record inside the relevant section on Online Scout Manager.
  3. Once the record is created in OSM, the admin runs the EMS Section Sync (`OSM_Section_Importer`) to pull them into `ems_osm_explorers`.
  4. The admin then uses the search dropdown in the React Inspector Panel to manually link the guest signup to the newly synced explorer, writing the new `scout_id` to the signup database row.

### 7.3 Database Option Write Overhead (Performance Safeguard)
* **The Risk:** Running `update_option( "ems_form_mappings_{$form_id}", ... )` inside a public shortcode on every page load can cause continuous write operations in the database under high traffic.
* **Mitigation:**
  1. Inside the shortcode render hook, check if the mapping config already matches:
     ```php
     $existing = get_option( "ems_form_mappings_{$form_id}" );
     if ( $existing !== $new_mappings ) {
         update_option( "ems_form_mappings_{$form_id}", $new_mappings, false ); // Pass false to prevent autoloading
     }
     ```
  2. Passing `false` as the third parameter to `update_option` prevents this dynamic option from autoloading on every page request across the entire site, preventing memory bloat.

### 7.4 Client-Side Script Scoping (Multiple Forms on Same Page)
* **The Risk:** If a page contains the main signup form and a secondary form (e.g., a header search or sidebar newsletter signup), the JS script could inject choices or event listeners into the wrong selectors, causing page errors.
* **Mitigation:**
  - The localized JS config enqueued by the shortcode must scope all DOM selectors strictly to the specific form's container wrapper ID (e.g. `#fluentform_6` instead of global document selectors like `select[name="signup_child"]`).
  - Target fields using:
    `document.querySelector('#fluentform_' + window.emsFields.formId + ' select[name="' + window.emsFields.scoutField + '"]')`
