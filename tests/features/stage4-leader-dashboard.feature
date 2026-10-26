Feature: Stage 4 - Unit Leader Invitations & Front-End Leader Dashboard

  Scenario: Bulk inviting unit leaders creates ems_leader accounts, sends password setup emails, and skips already invited leaders
    Given the following master units exist in the unit table:
      | id | unit_id | name        | short_code | leader_email               |
      | 1  | 10      | Kelso ESU   | BO-Kelso   | leader@kelso.scouts.org.uk |
      | 2  | 20      | Peebles ESU | TW-Peebles | leader@peebles.scouts.org  |
      | 3  | 30      | Gala ESU    | BO-Gala    |                            |
    And no WordPress user exists for "leader@kelso.scouts.org.uk"
    And a WordPress user already exists for "leader@peebles.scouts.org" with role "ems_leader" and meta "ems_leader_invited_at" set to "2026-10-01 10:00:00"
    When the administrator triggers bulk leader invitations
    Then a new WordPress user should be created for "leader@kelso.scouts.org.uk" with role "ems_leader"
    And WordPress password setup notification should be sent to "leader@kelso.scouts.org.uk"
    And the user for "leader@kelso.scouts.org.uk" should have meta "ems_leader_invited_at" recorded
    And no duplicate invitation email should be sent to "leader@peebles.scouts.org"
    And the bulk invitation summary should report 1 invited, 1 already invited, and 1 skipped without email

  Scenario: Unit Mapping table displays Invited and Logged In indicators for each unit leader
    Given unit "Kelso ESU" has leader_email "leader@kelso.scouts.org.uk" whose WP user has "ems_leader_invited_at" set and "ems_last_login_at" set
    And unit "Peebles ESU" has leader_email "leader@peebles.scouts.org" whose WP user has "ems_leader_invited_at" set and no "ems_last_login_at"
    And unit "Hawick ESU" has leader_email "leader@hawick.scouts.org" with no WP user account
    When the administrator views the Unit Mapping tab
    Then the row for "Kelso ESU" should display "Invited" and "Logged In" status badges
    And the row for "Peebles ESU" should display "Invited" and "Never Logged In" status badges
    And the row for "Hawick ESU" should display "Not Invited" status badge

  Scenario: Leader dashboard REST endpoint enforces leader capability and returns active signups without sensitive fields
    Given active participant and expedition signups exist in the database
    When an unauthenticated user or a user with role "ems_parent" requests "GET /ems/v1/leader-dashboard/signups"
    Then the response status should be 403
    When a user with role "ems_leader" requests "GET /ems/v1/leader-dashboard/signups"
    Then the response status should be 200
    And the response should contain active signups with explorer names, levels, units, and expedition types
    And the response should not expose parent emails, explorer emails, or additional support needs

  Scenario: Front-end Leader Dashboard renders explorers grouped by unit and sorted by first name with OSM sync icon, coloured level pills, and no inspector panel
    Given the following active signups exist for the leader dashboard:
      | id | type        | scout_id | explorer_first_name | explorer_last_name | dofe_level | unit_name   | exped_type  |
      | 10 | participant | 30003    | Zack                | Adams              | bronze     | BO-Kelso    |             |
      | 20 | expedition  | 30003    | Zack                | Adams              | bronze     | BO-Kelso    | Hillwalking |
      | 11 | participant | 0        | Alice               | Zimmerman          | silver     | BO-Kelso    |             |
      | 12 | expedition  | 30002    | Bob                 | Smith              | gold       | TW-Peebles  | Biking      |
    When an authorized unit leader views the "[ems-leader-dashboard]" page
    Then the toolbar should not contain a "Form Type" filter
    And the table should be grouped by unit with group headers "BO-Kelso" followed by "TW-Peebles"
    And within "BO-Kelso", "Alice Zimmerman" should appear before "Zack Adams" (sorted by first name)
    And the row for "Zack Adams" should display the OSM sync icon "assets/images/osm_icon.png"
    And the row for "Alice Zimmerman" should not display any icon in the OSM sync column
    And the level badges should render with coloured pill classes "ems-pill--bronze", "ems-pill--silver", and "ems-pill--gold"
    And the row for "Zack Adams" should show "✓" in both "Participant Place" and "Expedition Preference"
    And clicking a row in the leader dashboard table should not open an inspector panel
