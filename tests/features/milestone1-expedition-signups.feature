Feature: Expedition Signups Management
  As an EMS Administrator
  I want to manage specific expedition event entries
  So that I can allocate explorers to practice or qualifying expedition slots

  Scenario: A parent submits an expedition signup form
    Given the parent is logged in
    When the parent submits the Expedition form for explorer child 30001
    Then an expedition signup record is created with scout_id 30001
    And the expedition level is "Bronze"
    And the travel/transport preferences are saved

  Scenario: Listing expedition signups retrieves travel, teammate, and first aid details
    Given an expedition signup exists for explorer child 30001
    And the explorer has travel preferences "Hillwalking", teammates "Friend A", and first aid "first-response"
    When the administrator requests the expedition signups list
    Then the response contains the travel preferences, teammate list, and first aid status

  Scenario: Processing an expedition signup updates its status
    Given an expedition signup exists with status "pending"
    When the administrator processes the expedition signup
    Then the signup status is updated to "processed"
    And the processed by administrator and timestamp are recorded

  Scenario: Archiving an expedition signup updates its status
    Given an expedition signup exists with status "pending"
    When the administrator archives the expedition signup
    Then the signup status is updated to "archived"
