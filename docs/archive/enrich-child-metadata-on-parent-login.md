# Specification: Enriching Child Metadata on Parent Login (Session-Linked Transient Storage)

## Problem

When a parent logs in via OIDC, the `ems_children` user meta is populated with basic information:
```php
[
    'scout_id'    => int,
    'first_name'  => string,
    'last_name'   => string,
    'section_ids' => int[],
]
```

However, the expedition signup and participant forms require **email** and **date of birth** (DOB) for each child to pre-populate form fields. 
For security and privacy, we must **not** persist these sensitive details (DOB and email) permanently in our database tables (such as `ems_osm_explorers`) or long-term User Meta. Instead, they must be stored transiently for the duration of the user's active session.

---

## Technical Solution: Session-Linked Transient

Rather than storing the sensitive fields inside custom DB tables or long-term WP User Meta, we will write them to a WordPress Transient bound to the user's specific login session:
- **Transient Key**: `ems_sess_children_{session_hash}` (where `session_hash` is `md5( wp_get_session_token() )`).
- **Expiration**: Tied to the active WordPress session lifespan (typically 2 days, or 14 days if "Remember Me" was checked).
- **Security**: The DOB and email payload is encrypted using the server's `SECURE_AUTH_KEY` before saving to the transient, keeping the sensitive data secure at rest.
- **Auto-Cleanup**: Hooked into standard WordPress logout actions (`wp_logout`, `clear_auth_cookie`) to delete the transient immediately upon session termination.

---

## Detailed Data Fetching Flow

```
Parent OIDC login
  → OIDC_Login_Handler::handle_osm_login()
    → api_client->get_data_payload()                      // 1 API call (existing)
    → parser->parse_children($payload)                    // extracts from member_access (existing)
    
    // NEW: Enrich per child (2 API calls per child)
    → For each child in $children:
        → get_member_detail(section_id, scout_id, term_id)   // fetches email from ext/customdata/
        → get_contact_details(section_id, scout_id, term_id) // fetches DOB from ext/members/contact/

    // NEW: Storage & Hydration
    → Save first/last name of the parent user in WP core profile fields.
    → Save base children records (scout_id, names, section_ids) in `ems_children` User Meta.
    
    // NEW: Encrypt & Store sensitive enrichment data (email, DOB) in the session transient:
    → $session_hash = md5( wp_get_session_token() );
    → $encrypted_data = ems_encrypt_data( $enriched_data );
    → set_transient( 'ems_sess_children_' . $session_hash, $encrypted_data, $session_lifespan )
```

---

## Form Population Logic Updates

When rendering Fluent Forms, we combine the long-term child meta structure with the active session transient to populate form fields dynamically on the frontend.

### 1. `Fluent_Forms_Sync::enqueue_form_script`
When injecting Javascript mappings for the form pre-population:
- Load the children meta from `ems_children`.
- Retrieve the current session token hash: `md5( wp_get_session_token() )`.
- Fetch the matching session transient `ems_sess_children_{session_hash}` and decrypt it.
- If present, merge the transient's `email` and `dob` fields into the child arrays.
- Populate `$js_mappings` with `explorerEmail` and `dob` fields:
  ```php
  $js_mappings[ $scout_id ] = [
      'firstName'     => $child['first_name'] ?? '',
      'lastName'      => $child['last_name'] ?? '',
      'unitCode'      => $res['short_code'],
      'unitId'        => $res['unit_id'],
      'explorerEmail' => $child['email'] ?? '',
      'dob'           => $child['dob'] ?? '',
      'leaderEmail'   => $res['leader_email'],
  ];
  ```

### 2. Frontend JS Population
Extend the injected JavaScript in `Fluent_Forms_Sync.php` to handle the DOB field configuration:
- Add `dobField` to `window.emsFields` using the mapped Fluent Forms field name.
- When a child is selected from the dropdown, populate the DOB field:
  ```javascript
  var dobInput = document.querySelector('input[name="' + window.emsFields.dobField + '"]');
  if (dobInput && mapping.dob) {
      dobInput.value = mapping.dob;
      dobInput.dispatchEvent(new Event('change', { bubbles: true }));
  }
  ```

---

## File Changes & API Signatures

### 1. `src/Integrations/Drivers/Driver_Interface.php`
Add the new interface signature:
```php
public function get_contact_details( int $section_id, int $scout_id, int $term_id ): array;
```

### 2. `src/Integrations/Drivers/Live_Driver.php`
Implement request execution for DOB contact details:
```php
public function get_contact_details( int $section_id, int $scout_id, int $term_id ): array {
    $base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
    $url = add_query_arg( [
        'action'     => 'getContactDetails',
        'section_id' => $section_id,
        'member_id'  => $scout_id,
    ], $base . '/ext/mymember/details/' );

    return $this->request( $url );
}
```

### 3. `src/Integrations/Drivers/Mock_Driver.php`
Load mock response:
```php
public function get_contact_details( int $section_id, int $scout_id, int $term_id ): array {
    return $this->load( 'osm-get-contact-details.json' );
}
```
Create a new mock file at `tests/mocks/osm-get-contact-details.json` containing `data.data.dob`.

### 4. `src/Integrations/OSM_Parser.php`
Parse the DOB response:
```php
public function parse_contact_details( array $raw ): array {
    return [
        'scout_id'   => (int) ( $raw['data']['scoutid'] ?? 0 ),
        'first_name' => $raw['data']['firstname'] ?? '',
        'last_name'  => $raw['data']['lastname'] ?? '',
        'dob'        => $raw['data']['dob'] ?? '',
    ];
}
```

### 5. `src/Integrations/OSM_API_Client.php`
Wrap the driver method:
```php
public function get_contact_details( int $section_id, int $scout_id, int $term_id ): array {
    $this->rate_limiter->consume();
    $start = microtime( true );
    try {
        $data = $this->driver->get_contact_details( $section_id, $scout_id, $term_id );
    } finally {
        $this->after_call( 'get_contact_details', $start );
    }
    return $this->parser->parse_contact_details( $data );
}
```

---

## Session Cleanup & Hooks
To ensure immediate deletion upon user sign-out:
```php
add_action( 'wp_logout', function() {
    $session_token = wp_get_session_token();
    if ( $session_token ) {
        delete_transient( 'ems_sess_children_' . md5( $session_token ) );
    }
} );
```

---

## Error Handling & Resilience
- Every API call per child is wrapped in a `try/catch` block. 
- If a call fails, we log it via `error_log` and proceed to the next child, ensuring login completes successfully.
