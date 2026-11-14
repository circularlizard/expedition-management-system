# Specification: In-Form Email OTP Verification for Guest Signups (Multi-Field, Conditional Validation & Deduplication Support)

This document specifies the architecture, security safeguards, and implementation steps for integrating **One-Time Passcode (OTP) Verification** into Fluent Forms signup forms for guest (unauthenticated) parents. It supports verifying both parent and explorer email fields dynamically, conditionally handling validation based on login status, deduplicating verification for duplicate emails, and providing instant inline user feedback as they type.

---

## 1. Design Decisions & Integration Rules

### 1.1 OIDC Authentication Bypass & Conditional Validation
* **Parent Email Field OTP Bypass:** Parents logged in via Online Scout Manager (OIDC) have already had their identities and email addresses verified by the identity provider.
  * **Behavior:** Bypasses OTP verification for the **parent email field** only.
  * **UI:** The Parent Verification Code field (`signup_parent_otp_code`) and its trigger button are hidden automatically using CSS rules injected by the plugin.
  * **Frontend/Backend Bypass:** Strips `required` constraints on the frontend and unsets the validator check on submit for the parent OTP field if `is_user_logged_in()` is true.
* **Explorer Email Field OTP Requirements:** Because explorer email addresses are not synchronized or retrieved from the OSM payload, they must always be typed manually.
  * **Behavior:** The explorer email verification code (`signup_explorer_otp_code`) **remains visible and required** for both logged-in parents and guest parents.

### 1.2 Duplicate Email Deduplication (Same Email for Parent & Explorer)
If the parent types the same email address in both the parent email and the explorer email fields:
* **Behavior:** The user does not need to verify the same inbox twice.
* **Backend Bypass Logic:**
  - If the parent is logged in (meaning their parent email is OIDC-verified) and the explorer email matches the parent email, the explorer OTP verification is automatically bypassed.
  - If the parent is a guest, and they successfully verify the parent email OTP, and the explorer email matches the parent email, the explorer OTP verification is automatically bypassed.

### 1.3 Dynamic Field Naming & Configuration
To avoid hardcoding field names, we map target email fields to corresponding OTP code fields. 
The configuration shortcode attributes:
* `parent_email_field` (Default: `signup_parent_email`)
* `parent_otp_field` (Default: `signup_parent_otp_code`)
* `explorer_email_field` (Default: `signup_explorer_email`)
* `explorer_otp_field` (Default: `signup_explorer_otp_code`)

When `[ems_signup_banner]` renders, it updates the global settings map.

---

## 2. Technical Architecture

### 2.1 UI Layout & CSS Classes
For guests, each verification loop has:
1. **Email Field:** e.g. `signup_parent_email`
2. **Custom HTML (Button):** A trigger button wrapped in `<div class="ems-otp-wrap" data-target="signup_parent_email">` placed below the email field.
3. **Verification Code Field:** e.g. `signup_parent_otp_code`

CSS rules injected by the shortcode banner when logged in:
```css
/* Hide ONLY parent verification inputs for logged-in parents */
body.logged-in .ff-el-group:has([name^="signup_parent_otp_code"]),
body.logged-in .ems-otp-wrap[data-target="signup_parent_email"] {
    display: none !important;
}
```

### 2.2 AJAX Actions
We implement two separate AJAX hooks to power this flow:

#### Action A: Generate & Send OTP Code (`wp_ajax_send_fluent_otp`)
1. User enters email and clicks **"Send Verification Code"** button.
2. AJAX generates a secure 6-digit PIN and stores the SHA-256 hash in a transient: `fluent_otp_[md5_email_field]`.
   * **Key Format:** `$transient_key = 'fluent_otp_' . md5($email . '_' . $field_name);`
3. Sends email via `wp_mail()`.
4. Disables the button for 60 seconds (cooldown).

#### Action B: Inline Real-Time Code Verification (`wp_ajax_verify_fluent_otp`)
1. As the user types into the **Verification Code** input:
   * When the input length reaches exactly 6 characters (or loses focus), the JS automatically fires a background AJAX request to verify the code:
     * `action`: `verify_fluent_otp`
     * `email`: Target email
     * `field_name`: Target email field name
     * `code`: User entered 6-digit PIN
2. **Backend Handler (`handle_verify_fluent_otp`):**
   * Computes the timing-safe comparison `hash_equals()` against the transient `fluent_otp_[md5_email_field]`.
   * Returns JSON success/failure (but does NOT delete the transient yet, so that the final form submission validation still runs and records validation).
3. **Frontend Feedback:**
   * **If Valid:** Displays a green badge next to the input: *"✓ Email verified!"*, changes the input border to green, and locks/marks the email input as read-only.
   * **If Invalid:** Displays a red error message: *"✗ Incorrect verification code."*

### 2.3 Form Submission Validation Hook (Ultimate Security Guard)
During Fluent Forms validation, the backend interceptor runs:
* Hook: `fluentform/validate_input_item_email`
* **Logic:**
  1. Retrieve active form mappings.
  2. Determine if the current validated field name matches either the mapped `parent_email_field` or `explorer_email_field`.
  3. If it is `parent_email_field` and the user is logged in, bypass check (`return $errorMessage`).
  4. If it is `explorer_email_field`:
     * If the entered explorer email matches the parent's email:
       - If the parent is logged in (OIDC verified), bypass check.
       - If the parent is a guest, and the submitted parent OTP code is correct, bypass check.
  5. Find the corresponding OTP field name (e.g. if `signup_parent_email`, OTP is `signup_parent_otp_code`).
  6. Fetch the submitted code. If empty, return error: *"Please verify this email address with the 6-digit code."*
  7. Retrieve transient `fluent_otp_[md5_email_field]`. If missing, return error: *"The verification code has expired or was not requested."*
  8. Timing-safe comparison `hash_equals()`. If mismatch, return error: *"The verification code is incorrect."*
  9. **Success:** Clear the transient immediately (`delete_transient`) to prevent replay attack.

### 2.4 Conditional Validation Hooks (The Required Bypass)
To prevent submission blocks when fields are hidden for logged-in users, the plugin registers:

1. **Backend Validation Override:**
   * Hook: `fluentform/validation_rules`
   * Action: If `is_user_logged_in()`, search the `$rules` array for the mapped `parent_otp_field` and unset the `required` constraint.
2. **Frontend Field Rendering Override:**
   * Hooks: `fluentform/rendering_field_data_input_text` and `fluentform/rendering_field_data_input_number`
   * Action: If `is_user_logged_in()`, intercept the field data array matching the `parent_otp_field` and unset `$data['attributes']['required']` and `$data['settings']['validation_rules']['required']`.

---

## 3. Security Hardening Checklist

* **Timing Attack Prevention:** `hash_equals()` is used for code verification.
* **Hashed transient storage:** OTP is stored in memory as `hash('sha256', $otp)` to protect against database leak exposures.
* **OTP Replay Protection:** Transient is deleted immediately upon successful validation.
* **AJAX Nonce:** Validates `check_ajax_referer` to prevent cross-site form spam.
* **Rate Limiting:** IP/Email-based 60s cooldown transient preventing bulk transaction mailer abuse.

---

## 4. Implementation Steps

1. **Shortcode & Options Setup:**
   * Update `render_signup_banner_shortcode` in `Plugin.php` to accept `parent_otp_field` (default `signup_parent_otp_code`) and `explorer_otp_field` (default `signup_explorer_otp_code`) and save them.
   * Add inline CSS rules hiding `.ems-otp-wrap[data-target="signup_parent_email"]` and `:has([name^="signup_parent_otp_code"])` when logged in.
   
2. **AJAX and Hooks Registration:**
   * Add AJAX endpoints `send_fluent_otp` and `verify_fluent_otp` in `Fluent_Forms_Sync::init_hooks()`.
   * Add the `fluentform/validate_input_item_email` filter in `Fluent_Forms_Sync::init_hooks()`.
   * Register the `fluentform/validation_rules` filter and field rendering overrides in `Fluent_Forms_Sync::init_hooks()` to strip `required` validation dynamically for the parent OTP field if logged in.

3. **Frontend JS Logic:**
   * Update the enqueued JS code block inside `Fluent_Forms_Sync::enqueue_form_script` to bind click events to all OTP trigger buttons matching `.ems-otp-wrap button`, handle AJAX dispatching dynamically using the button's target attribute, and manage individual 60-second cooldown timers.
   * Add a listener to the OTP inputs. When length reaches 6, perform background AJAX verification call and update UI with dynamic verification badges (green check / red cross) inline.
   * Add a listener to the explorer email input. If it changes to match the parent email, automatically hide the explorer OTP code field and set its verification status to success.
