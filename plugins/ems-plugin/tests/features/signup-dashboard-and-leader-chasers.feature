Feature: Signups Dashboard Consolidation, OSM Sync Consistency & Leader Chasers

  Scenario: Unified Signups by DofE Level widget displays unique explorer counts and per-level issue breakdowns without progress bars
    Given the following active signups exist:
      | id | type        | scout_id | explorer_name | dofe_level | is_synced_osm |
      | 10 | participant | 30001    | Alice Smith   | bronze     | 1             |
      | 15 | participant | 30001    | Alice Smith   | bronze     | 1             |
      | 11 | participant | 0        | Bob Jones     | silver     | 0             |
      | 12 | participant | 30002    | Charlie Brown | gold       | 1             |
      | 20 | expedition  | 30002    | Charlie Brown | gold       | 1             |
      | 21 | expedition  | 30003    | Dana White    | silver     | 1             |
    When the administrator opens the Signups Dashboard tab
    Then the unified Signups by DofE Level widget should display 4 unique explorers (3 Place, 2 Exped) instead of 6 form submissions
    And the unified widget should not render any progress bars
    And the Bronze section should show 1 unique explorer (1 Place, 0 Exped) and 1 Place Only issue
    And the Silver section should show 2 unique explorers (1 Place, 1 Exped), 1 Place Only issue, 1 Exped Only issue, and 1 No Synced OSM issue
    And the Gold section should show 1 unique explorer (1 Place, 1 Exped) and 1 Paired explorer
    When the administrator clicks the Silver Place Only issue link
    Then the view should switch to the Signups Table filtered to Silver participant signups with anomaly "no_expedition"

  Scenario: OSM Sync column badge and sorting match the No Synced OSM Record count even when scout_id is non-zero
    Given an active signup exists for "Eve Green" with scout_id 99999 and is_synced_osm false
    And an active signup exists for "Frank Black" with scout_id 30001 and is_synced_osm true
    When the administrator views the Signups Dashboard
    Then the "No Synced OSM Record" card should show 1 unmatched explorer
    When the administrator switches to the Signups Table tab and sorts by "OSM Sync" ascending
    Then "Eve Green" should appear before "Frank Black"
    And "Eve Green" should display the unmatched warning icon instead of a synced checkmark
    And clicking "Eve Green" should explain in the Inspector that Scout ID 99999 is not yet synced from OSM

  Scenario: Missing OSM Records tab groups unsynced explorers by unit and sends an editable HTML email to the unit leader
    Given unit "Kelso ESU" has leader_email "leader@kelso.scouts.org.uk"
    And unit "Kelso ESU" has unsynced Bronze explorers "Zoe Adams" and "Amy Brown" and synced Bronze explorer "Chris Davis"
    When the administrator opens the "Missing OSM Records" tab
    Then "Kelso ESU" should list "Amy Brown" and "Zoe Adams" as missing OSM records
    And the toolbar should include a link to FluentSMTP email logs
    When the administrator clicks "Preview & Edit Email" for "Kelso ESU"
    Then the preview should be addressed to "leader@kelso.scouts.org.uk"
    And the HTML body should populate "{{explorer_list_osm}}" with "Amy Brown" before "Zoe Adams" under Bronze and omit "Chris Davis"
    And the HTML body should populate "{{explorer_list}}" with "Amy Brown", "Chris Davis", and "Zoe Adams" under Bronze
    When the administrator edits the subject and clicks "Send Email"
    Then the HTML email should be sent via wp_mail with the edited subject
    And "Kelso ESU" should display a "Last requested" timestamp

  Scenario: Unpaired Signups tab groups incomplete signups by unit and populates place-only and expedition-only explorer lists
    Given unit "Peebles ESU" has leader_email "leader@peebles.scouts.org.uk"
    And in "Peebles ESU", "Liam Scott" (Bronze) has only a Participant Place signup
    And in "Peebles ESU", "Maya Patel" (Silver) has only an Expedition signup
    And in "Peebles ESU", "Noah King" (Bronze) has both Participant Place and Expedition signups
    When the administrator opens the "Unpaired Signups" tab
    Then "Peebles ESU" should list "Liam Scott" (Place Only) and "Maya Patel" (Exped Only) and omit "Noah King"
    When the administrator clicks "Preview & Edit Email" for "Peebles ESU"
    Then "{{place_only_explorer_list}}" should contain "Liam Scott" under Bronze
    And "{{exped_only_explorer_list}}" should contain "Maya Patel" under Silver
    And "{{explorer_list}}" should contain "Liam Scott", "Noah King" under Bronze and "Maya Patel" under Silver

  Scenario: Sending a chaser email fails gracefully when the Notifications module is disabled
    Given the "notifications" feature flag is disabled
    When the administrator sends a POST request to "/ems/v1/signups/send-chaser"
    Then the API should return a 503 WP_Error with code "ems_notifications_disabled"

  Scenario: No Synced OSM Record card distinguishes candidate matches from no-match explorers
    Given a synced OSM explorer exists for "Alice Smith" (scout_id 30001, email "alice@example.com")
    And an unmatched signup exists for "Alice Smith" (scout_id 0, email "alice@example.com")
    And an unmatched signup exists for "Unknown Person" (scout_id 0, email "nobody@example.com")
    When the administrator views the Signups Dashboard
    Then the "No Synced OSM Record" card should show 1 explorer with a candidate match and 1 explorer with no match

  Scenario: Chaser shortcodes and UI level filter support filtering by DofE level
    Given unit "Kelso ESU" has unsynced Bronze explorer "Amy Brown" and unsynced Gold explorer "Zoe Adams"
    When the template uses "{{explorer_list_osm levels=\"bronze\"}}"
    Then the rendered list should include "Amy Brown" under Bronze and omit "Zoe Adams"
    When the administrator unchecks "Gold" in the Missing OSM Records tab and clicks "Preview & Edit Email" for "Kelso ESU"
    Then the preview request should include levels ["bronze", "silver"] and exclude "Zoe Adams"
