Feature: Participant Place Signups Management
  As an EMS Administrator
  I want to manage DofE Participation Place registrations
  So that I can allocate DofE slots and record eDofE numbers

  Scenario: A parent submits a DofE participant place signup form
    Given the parent is logged in
    When the parent submits the Participant Place form for explorer child 30001
    Then a participant place signup record is created with scout_id 30001
    And the signup status is "received"

  Scenario: Listing participant signups retrieves biographical and prior completion details
    Given a participant place signup exists for explorer child 30001
    And the explorer has completed "Volunteering" and "Skills" for the prior level
    When the administrator requests the participant signups list
    Then the response contains the explorer's email, ESU, and completed prior level sections

  Scenario: Processing a participant place signup marks it allocated and records the eDofE number
    Given a participant place signup exists with status "received" and no DofE number
    When the administrator allocates a place for the signup with eDofE number "1234567"
    Then the signup status is updated to "allocated"
    And the DofE number "1234567" is recorded on the signup record
    And the processed by administrator and timestamp are recorded

  Scenario: Archiving a participant place signup updates its status
    Given a participant place signup exists with status "received"
    When the administrator archives the signup
    Then the signup status is updated to "archived"
