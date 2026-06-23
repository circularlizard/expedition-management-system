Feature: Explorer assignment to teams and events
  # Happy paths: add to team, remove from team, move within event, move across events of same type
  # Edge cases: add duplicate, move to incompatible event type, move non-existent explorer

  Background:
    Given the current user has the "manage_options" capability
    And a season exists with year "2026-27" and status "active"
    And an event "H-SP1" of type "practice" exists in the season
    And a team "H-SP1-1" exists in event "H-SP1"
    And an explorer "Alice MacLeod" exists in the OSM reference data
    And an explorer "Bob Stewart" exists in the OSM reference data

  # --- Happy paths ---

  Scenario: Add an explorer to a team
    When "Alice MacLeod" is added to team "H-SP1-1"
    Then team "H-SP1-1" has 1 member
    And "Alice MacLeod" is in team "H-SP1-1"

  Scenario: Remove an explorer from a team (team still has other members)
    Given "Alice MacLeod" is a member of team "H-SP1-1"
    And "Bob Stewart" is a member of team "H-SP1-1"
    When "Alice MacLeod" is removed from team "H-SP1-1"
    Then team "H-SP1-1" has 1 member
    And "Alice MacLeod" is not in team "H-SP1-1"

  Scenario: Move an explorer between teams within the same event
    Given a team "H-SP1-2" exists in event "H-SP1"
    And "Alice MacLeod" is a member of team "H-SP1-1"
    When "Alice MacLeod" is moved to team "H-SP1-2"
    Then "Alice MacLeod" is in team "H-SP1-2"
    And "Alice MacLeod" is not in team "H-SP1-1"

  Scenario: Move an explorer from one event to another event of the same type
    Given an event "H-SP2" of type "practice" exists in the season
    And a team "H-SP2-1" exists in event "H-SP2"
    And "Alice MacLeod" is a member of team "H-SP1-1"
    When "Alice MacLeod" is moved to team "H-SP2-1"
    Then "Alice MacLeod" is in team "H-SP2-1"
    And "Alice MacLeod" is not in team "H-SP1-1"

  # --- Edge cases ---

  Scenario: Cannot add an explorer who is already in the team
    Given "Alice MacLeod" is a member of team "H-SP1-1"
    When "Alice MacLeod" is added to team "H-SP1-1"
    Then an error is returned with code "ems_member_already_in_team"

  Scenario: Cannot move an explorer to a team in an event of a different type
    Given an event "H-SQ1" of type "qualifying" exists in the season
    And a team "H-SQ1-1" exists in event "H-SQ1"
    And "Alice MacLeod" is a member of team "H-SP1-1"
    When "Alice MacLeod" is moved to team "H-SQ1-1"
    Then an error is returned with code "ems_incompatible_event_type"

  Scenario: Removing the last explorer from a team deletes the team
    Given "Alice MacLeod" is the only member of team "H-SP1-1"
    When "Alice MacLeod" is removed from team "H-SP1-1"
    Then team "H-SP1-1" no longer exists

  Scenario: Cannot add a non-existent explorer to a team
    When an explorer who does not exist in the OSM reference data is added to team "H-SP1-1"
    Then an error is returned with code "ems_explorer_not_found"
