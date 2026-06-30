Feature: Assign WordPress Roles and Map Relationships on OIDC Login

  As a system administrator
  I want WordPress user roles and relationships to be dynamically assigned on OIDC login
  So that parents, explorers, and leaders have correct capabilities and access permissions.

  Scenario: Parent logs in and receives ems_parent role with child mappings
    Given a WP user exists with ID 42 and email "parent@example.com"
    And the user has the default role "subscriber"
    When the user logs in via OIDC with access token "parent-token" returning access type "parent"
    Then the user should have the role "ems_parent"
    And the user should not have the role "subscriber"
    And the user meta "ems_access_type" should be "parent"
    And the user meta "ems_scout_ids" should contain child scout IDs
    And the user meta "ems_children" should contain serialized child mapping details

  Scenario: Explorer logs in and receives ems_explorer role
    Given a WP user exists with ID 43 and email "explorer@example.com"
    And the user has the default role "subscriber"
    When the user logs in via OIDC with access token "explorer-token" returning access type "member"
    Then the user should have the role "ems_explorer"
    And the user should not have the role "subscriber"
    And the user meta "ems_access_type" should be "member"

  Scenario: Leader logs in and receives ems_leader role
    Given a WP user exists with ID 44 and email "leader@example.com"
    And the user has the default role "subscriber"
    When the user logs in via OIDC with access token "leader-token" returning access type "local"
    Then the user should have the role "ems_leader"
    And the user should not have the role "subscriber"
    And the user meta "ems_access_type" should be "local"

  Scenario: Leader with administered sections receives ems_leader role
    Given a WP user exists with ID 45 and email "leader-section@example.com"
    And the user has the default role "subscriber"
    When the user logs in via OIDC with access token "leader-section-token" returning access type "local" and section IDs
    Then the user should have the role "ems_leader"
    And the user meta "ems_section_ids" should contain section IDs

  Scenario: Gracefully handle OIDC login when OSM payload is missing critical fields
    Given a WP user exists with ID 46 and email "broken-payload@example.com"
    And the user has the default role "subscriber"
    When the user logs in via OIDC with a malformed access token that returns missing globals or member access
    Then the login should complete successfully without hard exceptions
    And the user should retain the role "subscriber"
