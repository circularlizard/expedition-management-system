Feature: Explorer move panel UI
  # Move a single explorer between teams (within event or across events of same type)
  # Happy paths: move within event, move across events, member counts update
  # Edge cases: last member removal deletes team, incompatible event type blocked

  Background:
    Given the user is on the Explorer Move Panel
    And a season exists with event "H-SP1" (practice) and event "H-SP2" (practice)
    And team "H-SP1-1" has members: "Alice MacLeod", "Bob Stewart", "Charlie Mackay"
    And team "H-SP1-2" has members: "Diana Fraser", "Ewan Campbell"

  # --- Happy paths ---

  Scenario: Moving an explorer to another team within the same event updates both team member counts
    When the user moves "Alice MacLeod" from "H-SP1-1" to "H-SP1-2"
    Then team "H-SP1-1" shows 2 members
    And team "H-SP1-2" shows 3 members
    And "Alice MacLeod" is listed under "H-SP1-2"
    And "Alice MacLeod" is not listed under "H-SP1-1"

  Scenario: Moving an explorer across events of the same type updates both events
    Given team "H-SP2-1" exists in event "H-SP2" with members: "Fiona Grant"
    When the user moves "Alice MacLeod" from "H-SP1-1" to "H-SP2-1"
    Then "Alice MacLeod" is listed under "H-SP2-1"
    And "Alice MacLeod" is not listed under "H-SP1-1"
    And team "H-SP2-1" shows 2 members

  Scenario: The panel shows available target teams in the same event
    When the user selects "Alice MacLeod" in team "H-SP1-1"
    Then the target dropdown includes team "H-SP1-2"

  Scenario: The panel shows available target teams in other events of the same type
    Given team "H-SP2-1" exists in event "H-SP2"
    When the user selects "Alice MacLeod" in team "H-SP1-1"
    Then the target dropdown includes teams in event "H-SP2"

  # --- Edge cases ---

  Scenario: Moving the last member from a team removes the team from the view
    Given team "H-SP1-3" exists with only member "Gillian Ross"
    When the user moves "Gillian Ross" from "H-SP1-3" to "H-SP1-1"
    Then team "H-SP1-3" is no longer visible in the event
    And "Gillian Ross" is listed under "H-SP1-1"

  Scenario: Target teams in events of a different type are not shown
    Given an event "H-SQ1" of type "qualifying" exists with team "H-SQ1-1"
    When the user selects "Alice MacLeod" in team "H-SP1-1"
    Then the target dropdown does not include teams in event "H-SQ1"
