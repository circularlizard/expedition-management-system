Feature: Season Dashboard UI
  # Happy paths: renders event list, expands to teams, shows member counts
  # Edge cases: empty season, team size warning badge, no seasons at all

  Background:
    Given the user is on the Season Dashboard page

  # --- Happy paths ---

  Scenario: Dashboard shows all events for the active season grouped by level
    Given the active season has events:
      | code  | type     | level  |
      | H-SP1 | practice | silver |
      | H-SQ1 | qualifying | silver |
      | B-SP1 | practice | bronze |
    When the dashboard renders
    Then 3 event cards are visible
    And event "H-SP1" appears under the "Silver" group

  Scenario: Event card shows team count and total member count
    Given the active season has event "H-SP1" with 2 teams having 5 and 4 members
    When the dashboard renders
    Then event "H-SP1" shows "2 teams"
    And event "H-SP1" shows "9 members"

  Scenario: Clicking an event card expands it to show its teams
    Given the active season has event "H-SP1" with teams "H-SP1-1" and "H-SP1-2"
    When the user clicks on event "H-SP1"
    Then the team list for "H-SP1" is visible
    And "H-SP1-1" and "H-SP1-2" are listed

  Scenario: Each team row shows its member names
    Given event "H-SP1" has team "H-SP1-1" with member "Alice MacLeod"
    When the user expands event "H-SP1"
    Then "Alice MacLeod" is listed under team "H-SP1-1"

  # --- Edge cases ---

  Scenario: Empty season shows a prompt to create the first event
    Given the active season has no events
    When the dashboard renders
    Then a "Create your first event" prompt is visible
    And no event cards are shown

  Scenario: Team outside 4–7 range shows a size warning badge
    Given the active season has event "H-SP1" with team "H-SP1-1" having 8 members
    When the user expands event "H-SP1"
    Then team "H-SP1-1" shows a size warning badge

  Scenario: Team within 4–7 range shows no size warning badge
    Given the active season has event "H-SP1" with team "H-SP1-1" having 5 members
    When the user expands event "H-SP1"
    Then team "H-SP1-1" does not show a size warning badge

  Scenario: No seasons exist shows a prompt to create the first season
    Given no seasons exist
    When the dashboard renders
    Then a "Create your first season" prompt is visible
