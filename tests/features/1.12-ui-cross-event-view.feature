Feature: Cross-event team view UI
  # Shows where the same members appear across events of the same type/level
  # Happy paths: member overlap visible, update assignment reflects immediately
  # Edge cases: member with no other assignments, no events of same type

  Background:
    Given the user is on the Cross-Event Team View page
    And a season exists with events "H-SP1" (practice/silver) and "H-SP2" (practice/silver)

  # --- Happy paths ---

  Scenario: Selecting a team shows member assignments in other events of same type
    Given team "H-SP1-1" has members: "Alice MacLeod", "Bob Stewart"
    And "Alice MacLeod" is also in team "H-SP2-1"
    When the user selects team "H-SP1-1"
    Then "Alice MacLeod" is shown with assignment "H-SP2-1" in event "H-SP2"
    And "Bob Stewart" is shown with assignment "not yet assigned" in event "H-SP2"

  Scenario: Member assignment in another event can be updated from this view
    Given team "H-SP1-1" has member "Alice MacLeod"
    And event "H-SP2" has teams "H-SP2-1" and "H-SP2-2"
    And "Alice MacLeod" is currently in team "H-SP2-1"
    When the user changes "Alice MacLeod"'s assignment in "H-SP2" to "H-SP2-2"
    Then "Alice MacLeod" is shown with assignment "H-SP2-2" in event "H-SP2"

  Scenario: View shows all practice events in the season when a practice team is selected
    Given a third event "H-SP3" of type "practice" exists
    When the user selects team "H-SP1-1"
    Then columns for "H-SP2" and "H-SP3" are shown
    And no qualifying events are shown

  # --- Edge cases ---

  Scenario: Member with no assignments in other events shows "not yet assigned"
    Given team "H-SP1-1" has member "Charlie Mackay"
    And "Charlie Mackay" is not in any team in event "H-SP2"
    When the user selects team "H-SP1-1"
    Then "Charlie Mackay" is shown as "not yet assigned" in event "H-SP2"

  Scenario: When no other events of the same type exist the view shows an empty state
    Given no other practice events exist in the season besides "H-SP1"
    When the user selects team "H-SP1-1"
    Then the message "No other practice events in this season" is shown

  Scenario: Selecting no team shows a prompt to select a team
    When no team is selected
    Then the message "Select a team to see cross-event assignments" is shown
