# Enriching Child Metadata on Parent Login

## Problem

When a parent logs in via OIDC, the `ems_children` user meta is populated with only:
```php
[
    'scout_id'    => int,
    'first_name'  => string,
    'last_name'   => string,
    'section_ids' => int[],
]
```

The signup form and parent portal need **email** and **date of birth** for each child to pre-populate form fields. Neither field is available in the `getDataPayload` response.

## Current Flow (for reference)

```
Parent OIDC login
  → OIDC_Login_Handler::handle_osm_login()
    → api_client->get_data_payload()          // 1 API call
    → parser->parse_children($payload)        // extracts from member_access
    → update_user_meta( 'ems_children', $children )
```

The `member_access` block in `getDataPayload` contains only `access_type`, `first_name`, and `last_name` per child. No email or DOB.

## Data Sources

### Email
- **API**: `get_member_detail(section_id, scout_id, term_id)` → `ext/customdata/`
- **Already implemented** in `OSM_API_Client::get_member_detail()`
- **Parser**: `OSM_Parser::parse_member_detail()` extracts `email` and `parent_email` from group_id=6, column_id=12/14
- **Mock data**: `tests/mocks/osm-member-detail.json` — keyed by `scout_id`, returns `{email, parent_email}`
- **Cost**: 1 API call per child

### Date of Birth
- **API**: `get_contact_details(section_id, scout_id, term_id)` → `ext/members/contact/` with `action=getContactDetails`
- **NOT yet implemented** — needs new driver method in `Driver_Interface`, `Live_Driver`, and `Mock_Driver`
- **No parser** — needs `OSM_Parser::parse_contact_details()`
- **Mock data**: Needs `tests/mocks/osm-get-contact-details.json` — should include `dob` (ISO format `YYYY-MM-DD`)
- **Cost**: 1 API call per child
- **Parent-safe**: This is a per-member endpoint. Unlike `get_section_members` (`getListOfMembers`), a parent who is **not** also a leader can call this for their own children.

## Recommended Approach

**Fetch DOB via `get_contact_details` + fetch email via `get_member_detail` — both per-child.**

Rationale:
- `get_section_members` (`getListOfMembers`) requires leader access — a parent-only account cannot call it
- `get_contact_details` (`getContactDetails`) is a per-member endpoint that a parent can call for their own children
- Email already requires a per-member call via `get_member_detail`
- 2 calls per child is acceptable (parents have 1-3 children)

### Step-by-step flow

```
Parent OIDC login
  → handle_osm_login()
    → get_data_payload()                              // 1 call (existing)
    → parse_access_type(), parse_scout_ids(), parse_section_ids()
    → parse_children()                                // existing, returns base children
    → parse_terms()                                   // needed to find current term per section

    // NEW: Enrich per child (2 calls per child)
    → For each child in $children:
        → get_member_detail(section_id, scout_id, term_id)       // email
        → get_contact_details(section_id, scout_id, term_id)     // DOB

    → Merge enrichments into $children array
    → update_user_meta('ems_children', $children)    // now includes email + dob
```

## Files to Modify

### 1. `src/Integrations/Drivers/Driver_Interface.php`

**Change**: Add `get_contact_details()` to the interface.

```php
public function get_contact_details( int $section_id, int $scout_id, int $term_id ): array;
```

### 2. `src/Integrations/Drivers/Live_Driver.php`

**Change**: Implement `get_contact_details()` — new action on existing endpoint.

```php
public function get_contact_details( int $section_id, int $scout_id, int $term_id ): array {
    $base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
    $url = add_query_arg( [
        'action'    => 'getContactDetails',
        'sectionid' => $section_id,
        'scoutid'   => $scout_id,
        'termid'    => $term_id,
    ], $base . '/ext/members/contact/' );

    return $this->request( $url );
}
```

### 3. `src/Integrations/Drivers/Mock_Driver.php`

**Change**: Implement `get_contact_details()` — load from `tests/mocks/osm-get-contact-details.json`.

```php
public function get_contact_details( int $section_id, int $scout_id, int $term_id ): array {
    return $this->load( 'osm-get-contact-details.json' );
}
```

**Also create**: `tests/mocks/osm-get-contact-details.json` — mock response including `dob` field.

### 4. `src/Integrations/OSM_API_Client.php`

**Change**: Add `get_contact_details()` wrapper method.

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

### 5. `src/Integrations/OSM_Parser.php`

**Change**: Add `parse_contact_details()` method to extract DOB.

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

### 6. `src/Integrations/OIDC_Login_Handler.php`

**Change**: After `parse_children()`, enrich each child with `email` and `dob`.

New private method:
```php
private function enrich_children(array $children, array $payload): array {
    $terms = $this->parser->parse_terms($payload);

    foreach ($children as &$child) {
        $scout_id = $child['scout_id'];
        foreach ($child['section_ids'] as $section_id) {
            $term = $this->parser->find_current_term($terms, $section_id);
            if (!$term) continue;

            // Fetch email via get_member_detail
            try {
                $detail = $this->api_client->get_member_detail(
                    $section_id, $scout_id, $term['term_id']
                );
                if (!empty($detail['email'])) {
                    $child['email'] = $detail['email'];
                }
                if (!empty($detail['parent_email'])) {
                    $child['parent_email'] = $detail['parent_email'];
                }
            } catch (\Exception $e) {
                error_log('[EMS] Failed to fetch email for scout ' . $scout_id . ': ' . $e->getMessage());
            }

            // Fetch DOB via get_contact_details (parent-safe, no leader access required)
            if (empty($child['dob'])) {
                try {
                    $contact = $this->api_client->get_contact_details(
                        $section_id, $scout_id, $term['term_id']
                    );
                    if (!empty($contact['dob'])) {
                        $child['dob'] = $contact['dob'];
                    }
                } catch (\Exception $e) {
                    error_log('[EMS] Failed to fetch DOB for scout ' . $scout_id . ': ' . $e->getMessage());
                }
            }
        }
    }
    unset($child);
    return $children;
}
```

Then call it in `handle_osm_login()` after the existing `parse_children()` call:
```php
$children = $this->parser->parse_children($payload);
if (!empty($children)) {
    $children = $this->enrich_children($children, $payload);
    update_user_meta($user->ID, 'ems_children', $children);
}
```

### 7. `src/Integrations/Fluent_Forms_Sync.php`

**Change**: Use `email` and `dob` from the `ems_children` meta when rendering the child dropdown and pre-populating form fields. Currently the dropdown only uses `first_name` and `last_name`. The `dob` can be used to populate a date-of-birth field in the form.

### 8. `tests/Unit/Integrations/OSM_ParserTest.php`

**Change**: Add test cases:
- `test_parse_contact_details_extracts_dob`
- `test_parse_contact_details_handles_missing_dob`

### 9. `tests/Unit/Integrations/OSM_API_ClientTest.php`

**Change**: Add test case:
- `test_get_contact_details_delegates_to_driver_and_parser`

### 10. `tests/Unit/Integrations/OIDC_Login_HandlerTest.php`

**Change**: Add test cases:
- `test_handle_osm_login_enriches_children_with_email_and_dob`
- `test_enrich_children_handles_missing_dob`
- `test_enrich_children_handles_missing_email`
- `test_enrich_children_handles_api_failure_gracefully`
- `test_enrich_children_uses_get_contact_details_not_get_section_members`

### 11. `tests/features/auth-oidc-mapping.feature`

**Change**: Update or add scenarios:
```gherkin
Scenario: Parent login enriches child records with email and date of birth
  Given a parent user with access to children
  When the parent logs in via OSM OIDC
  Then the user meta "ems_children" should contain "email" for each child
  And the user meta "ems_children" should contain "dob" for each child
```

## Resulting `ems_children` Structure

After the change, `ems_children` will contain:
```php
[
    [
        'scout_id'     => 30001,
        'first_name'   => 'Child',
        'last_name'    => 'One',
        'section_ids'  => [99001, 99002],
        'email'        => 'child.one@example.com',       // NEW
        'parent_email' => 'parent@example.com',           // NEW
        'dob'          => '2007-01-01',                   // NEW
    ],
    // ...
]
```

## Rate Limit Considerations

For a parent with N children:
- **Current**: 1 API call (`getDataPayload`)
- **After change**: 1 + 2N calls (`get_contact_details` + `get_member_detail` per child)
- **Typical parent**: 1 + 4 = **5 calls** (2 children)
- **Max expected**: 1 + 6 = **7 calls** (3 children)

The `Rate_Limiter` (10 calls/1s default) will handle this comfortably. The login hook runs synchronously, so the user will wait for all calls to complete — this is acceptable given the low call count.

## Error Handling

Each API call must be wrapped in try/catch. If a single `get_member_detail` or `get_contact_details` call fails:
- Log the error with `error_log()`
- Continue processing remaining children
- Persist whatever data was successfully retrieved
- The child record will simply lack `email` or `dob` if those specific calls failed

This is preferable to blocking the entire login because one child's data couldn't be fetched.

## Alternative Approaches Considered

### A: Fetch on-demand via REST API
Instead of enriching at login, add a new REST endpoint (`ems/v1/children/{scout_id}/detail`) that fetches email/DOB lazily when the parent portal requests it. This keeps login fast but adds latency to form rendering and requires caching logic.

**Rejected**: Parents have few children; the upfront cost is minimal and simpler. Caching would just duplicate what `ems_children` already does.

### B: Use `get_section_members` batch list for DOB
The `getListOfMembers` endpoint returns all members with `dob` in one call per section.

**Rejected**: Requires leader access. A parent-only account cannot call this endpoint. Every parent is not also a leader.

### C: Use `get_individual` for DOB
The `get_individual` endpoint (`action=getIndividual`) exists in the drivers and returns DOB. But the OSM API requires `getContactDetails` for parent-level access to contact details.

**Rejected**: `getContactDetails` is the correct endpoint for a parent to fetch their child's contact details. `getIndividual` may require leader access.

### D: Include in bulk OSM sync
Rely on the existing `OSM_Reference_Sync` bulk sync to populate `ems_osm_explorers` with email and DOB, then look up from the database.

**Not sufficient**: The bulk sync runs on a schedule, not at login time. A parent logging in for the first time would still see missing data until the next sync. The login-time enrichment ensures data is always fresh.

## Important Constraint

**`get_section_members` (`getListOfMembers`) requires leader access.** Only use per-member endpoints (`get_contact_details`, `get_member_detail`) for parent-only accounts. These are the only endpoints a parent OIDC token can call for their own children.
