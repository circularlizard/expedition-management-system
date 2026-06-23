Feature: Team move and duplicate panel UI
  # Move or duplicate a whole team to another event; populate qualifier from practice
  # Happy paths: move shows re-code preview, duplicate creates new team, populate copies all teams
  # Edge cases: incompatible type blocked, move to same event blocked

  Background:
    Given the user is on the Team Move/Duplicate Panel
    And a season exists with event "H-SP1" (practice) and event "H-SP2" (practice)
    And team "H-SP1-1" exists in event "H-SP1" with members: "Alice MacLeod", "Bob Stewart"

  # --- Move ---

  Scenario: Selecting a move target shows a re-code preview before confirming
    When the user selects "Move" for team "H-SP1-1" and chooses target event "H-SP2"
    Then a preview shows the team will be re-coded to "H-SP2-1"
    And the preview lists the members: "Alice MacLeod", "Bob Stewart"

  Scenario: Confirming a move relocates the team with its new code
    When the user confirms the move of team "H-SP1-1" to event "H-SP2"
    Then team "H-SP2-1" exists in event "H-SP2" with members: "Alice MacLeod", "Bob Stewart"
    And team "H-SP1-1" no longer exists in event "H-SP1"

  # --- Duplicate ---

  Scenario: Duplicating a team to another event creates a new team without removing the original
    Given an event "H-SQ1" of type "qualifying" exists in the season
    When the user selects "Duplicate" for team "H-SP1-1" and chooses target event "H-SQ1"
    And the user confirms the duplication
    Then team "H-SQ1-1" exists in event "H-SQ1" with members: "Alice MacLeod", "Bob Stewart"
    And team "H-SP1-1" still exists in event "H-SP1" with its original members

  Scenario: Duplicate preview shows the new team code before confirming
    Given an event "H-SQ1" of type "qualifying" exists in the season
    When the user selects "Duplicate" for team "H-SP1-1" and chooses target event "H-SQ1"
    Then a preview shows the new team will be coded "H-SQ1-1"

  # --- Populate from practice ---

  Scenario: Populate qualifier from practice button copies all practice teams to the qualifying event
    Given a team "H-SP1-2" exists in event "H-SP1" with members: "Charlie Mackay", "Diana Fraser"
    And an event "H-SQ1" of type "qualifying" exists in the season with no teams
    When the user clicks "Populate from H-SP1" for event "H-SQ1" and confirms
    Then event "H-SQ1" shows 2 teams: "H-SQ1-1" and "H-SQ1-2"
    And "H-SQ1-1" has members: "Alice MacLeod", "Bob Stewart"
    And "H-SQ1-2" has members: "Charlie Mackay", "Diana Fraser"

  Scenario: Populate from practice does not overwrite existing teams in the target event
    Given team "H-SQ1-1" already exists in event "H-SQ1"
    And an event "H-SQ1" of type "qualifying" exists in the season
    When the user attempts to populate "H-SQ1" from "H-SP1"
    Then a warning is shown: "Target event already has teams"
    And the operation requires explicit confirmation to proceed

  # --- Edge cases ---

  Scenario: Incompatible event type is not offered as a move target
    Given an event "H-SQ1" of type "qualifying" exists in the season
    When the user opens the move panel for team "H-SP1-1"
    Then event "H-SQ1" is not listed as a move target

  Scenario: The current event is not offered as a move target
    When the user opens the move panel for team "H-SP1-1"
    Then event "H-SP1" is not listed as a move target
