Feature: Training requirements configuration per event
  As an administrator
  I want to configure the required Tutor LMS courses for a specific expedition event
  So that the system can validate training status for that event.

  Scenario: Default training requirements for a new event are empty
    Given an expedition event "Hill Walking 2026" exists with ID 10
    When I request the training requirements configuration for event 10 via REST
    Then the response should contain an empty list of required course IDs

  Scenario: Save and retrieve training requirements for a specific event
    Given an expedition event "Hill Walking 2026" exists with ID 10
    When I update the training requirements for event 10 to course IDs [101, 102] via REST
    And I request the training requirements configuration for event 10 via REST
    Then the response should contain the required course IDs [101, 102]
