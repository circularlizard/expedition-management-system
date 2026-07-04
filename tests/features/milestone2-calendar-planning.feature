Feature: Milestone 2 - Event Planning and Scheduling Board
  As an administrator
  I want to view explorer availability based on signup preferences
  So that I can allocate them to planned practice and qualifier events

  Background:
    Given the current date is "2026-07-04"
    And the user is logged in as an administrator
    And a synced explorer exists with scout_id 4001, first_name "Alice", last_name "MacLeod", unit_name "SMESU"
    And a synced explorer exists with scout_id 4002, first_name "Bob", last_name "Smith", unit_name "Kelso"
    And a signup exists with scout_id 4001, dofe_level "silver", and preferences:
      """
      {"exped_type":"Hillwalking","exped_practice_dates":["H-SP1"],"exped_qualifier_dates":["H-SQ1"]}
      """
    And a signup exists with scout_id 4002, dofe_level "silver", and preferences:
      """
      {"exped_type":"Hillwalking","exped_practice_dates":["H-SP1", "H-SP2"],"exped_qualifier_dates":[]}
      """

  Scenario: Fetching the planning board lists active Silver and Gold events with stats
    Given an event exists with code "H-SP1", level "silver", type "practice", status "active"
    And an event exists with code "H-SP2", level "silver", type "practice", status "active"
    And an event exists with code "H-BP1", level "bronze", type "practice", status "active"
    When the administrator requests the planning board
    Then the planning board should return 2 events
    And event "H-SP1" availability count should be 2
    And event "H-SP2" availability count should be 1
    And Bronze event "H-BP1" should not be included in the response

  Scenario: Listing availability for a specific event code
    Given an event exists with code "H-SP1", level "silver", type "practice", status "active"
    And explorer 4002 is allocated to team "H-SP2-1" in another event "H-SP2"
    When the administrator lists availability for event "H-SP1"
    Then the response should contain explorer 4001 as unallocated
    And the response should contain explorer 4002 with allocated event code "H-SP2"

  Scenario: Allocating explorers as unallocated to a planned event
    Given an event exists with ID 102, code "H-SP1", level "silver", type "practice", status "active"
    When the administrator allocates explorers [4001, 4002] to event 102 as "unallocated"
    Then explorer 4001 should be assigned to the "UNALLOCATED" team of event 102
    And explorer 4002 should be assigned to the "UNALLOCATED" team of event 102

  Scenario: Allocating explorer to a new team deletes their old empty team
    Given an event exists with ID 102, code "H-SP1", level "silver", type "practice", status "active"
    And a team exists with ID 201, code "H-SP2-1" on event 103
    And explorer 4001 is assigned to team 201
    When the administrator allocates explorer 4001 to event 102 as "new_team"
    Then explorer 4001 should be assigned to a new sequential team on event 102
    And team 201 should be deleted because it is empty
