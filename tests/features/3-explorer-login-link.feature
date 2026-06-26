Feature: Link OSM data to WP login

  As an administrator or explorer
  I want explorer records to be linked to WordPress user accounts
  So that personal training progress, team assignments, and route cards can be associated with the correct user.

  Background:
    Given an explorer "Bob Smith" exists in the OSM reference data with scout ID 30002
    And "Bob Smith" has email "bob.smith@example.com"
    And "Bob Smith" has no wp_user_id set

  # --- OIDC login / account creation linking ---

  Scenario: Match explorer by email on successful OIDC login
    When a WP user logs in with email "bob.smith@example.com" and ID 10
    Then "Bob Smith" has wp_user_id set to 10

  Scenario: Match explorer by email on WP user creation
    When a WP user is created with email "bob.smith@example.com" and ID 11
    Then "Bob Smith" has wp_user_id set to 11

  Scenario: Already linked explorer is not modified on subsequent login
    Given "Bob Smith" has wp_user_id set to 10
    When a WP user logs in with email "bob.smith@example.com" and ID 10
    Then "Bob Smith" wp_user_id remains 10

  Scenario: Unknown email does not link any explorer on login
    When a WP user logs in with email "unknown@example.com" and ID 12
    Then no explorer is linked to wp_user_id 12

  # --- Bulk Reconciliation ---

  Scenario: Admin triggers bulk reconciliation to link matching unlinked accounts
    Given a WP user exists with email "bob.smith@example.com" and ID 13
    And an explorer "Charlie Brown" exists in the OSM reference data with scout ID 30003
    And "Charlie Brown" has email "charlie@example.com"
    And a WP user exists with email "charlie@example.com" and ID 14
    When the admin triggers bulk reconciliation of explorer links
    Then "Bob Smith" has wp_user_id set to 13
    And "Charlie Brown" has wp_user_id set to 14
    And the reconciliation summary reports 2 users linked, 0 already linked, and 0 unmatched
