Feature: Team move, duplicate, and populate-from-practice operations
  # Happy paths: move team, duplicate team, populate qualifier from practice
  # Edge cases: move to incompatible type, move to event that already has max code, duplicate to same event

  Background:
    Given the current user has the "manage_options" capability
    And a season exists with year "2026-27" and status "active"
    And an event "H-SP1" of type "practice" exists in the season
    And a team "H-SP1-1" exists in event "H-SP1" with members: "Alice MacLeod", "Bob Stewart", "Charlie Mackay"

  # --- Move team ---

  Scenario: Move a team to another event of the same type re-codes it
    Given an event "H-SP2" of type "practice" exists in the season
    When team "H-SP1-1" is moved to event "H-SP2"
    Then the team exists in event "H-SP2" with code "H-SP2-1"
    And the team no longer exists in event "H-SP1"
    And the moved team retains its original members: "Alice MacLeod", "Bob Stewart", "Charlie Mackay"

  Scenario: Moved team gets the next sequential code in the target event
    Given an event "H-SP2" of type "practice" exists in the season
    And a team "H-SP2-1" already exists in event "H-SP2"
    When team "H-SP1-1" is moved to event "H-SP2"
    Then the team exists in event "H-SP2" with code "H-SP2-2"

  Scenario: Moving a team renumbers remaining teams in the source event
    Given a team "H-SP1-2" exists in event "H-SP1" with members: "Diana Fraser", "Ewan Campbell"
    And an event "H-SP2" of type "practice" exists in the season
    When team "H-SP1-1" is moved to event "H-SP2"
    Then the remaining team in event "H-SP1" has code "H-SP1-1"

  # --- Duplicate team ---

  Scenario: Duplicate a team to another event creates a new team with the same members
    Given an event "H-SQ1" of type "qualifying" exists in the season
    When team "H-SP1-1" is duplicated to event "H-SQ1"
    Then a new team "H-SQ1-1" exists in event "H-SQ1"
    And the new team has members: "Alice MacLeod", "Bob Stewart", "Charlie Mackay"
    And team "H-SP1-1" still exists in event "H-SP1" with its original members

  Scenario: Duplicating to an event with existing teams assigns the next sequential code
    Given an event "H-SQ1" of type "qualifying" exists in the season
    And a team "H-SQ1-1" already exists in event "H-SQ1"
    When team "H-SP1-1" is duplicated to event "H-SQ1"
    Then the new team has code "H-SQ1-2"

  # --- Populate from practice ---

  Scenario: Populate a qualifying event from a practice event copies all teams
    Given a team "H-SP1-2" exists in event "H-SP1" with members: "Diana Fraser", "Ewan Campbell"
    And an event "H-SQ1" of type "qualifying" exists in the season with no teams
    When event "H-SP1" is used to populate event "H-SQ1"
    Then event "H-SQ1" has 2 teams
    And team "H-SQ1-1" has members: "Alice MacLeod", "Bob Stewart", "Charlie Mackay"
    And team "H-SQ1-2" has members: "Diana Fraser", "Ewan Campbell"

  Scenario: Populate from practice does not modify the source event
    Given a team "H-SP1-2" exists in event "H-SP1" with members: "Diana Fraser", "Ewan Campbell"
    And an event "H-SQ1" of type "qualifying" exists in the season with no teams
    When event "H-SP1" is used to populate event "H-SQ1"
    Then event "H-SP1" still has 2 teams with their original members

  # --- Edge cases ---

  Scenario: Cannot move a team to an event of a different type
    Given an event "H-SQ1" of type "qualifying" exists in the season
    When team "H-SP1-1" is moved to event "H-SQ1"
    Then an error is returned with code "ems_incompatible_event_type"
    And team "H-SP1-1" still exists in event "H-SP1"

  Scenario: Cannot move a team to the same event it is already in
    When team "H-SP1-1" is moved to event "H-SP1"
    Then an error is returned with code "ems_team_already_in_event"
