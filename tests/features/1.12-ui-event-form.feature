Feature: Event create/edit form UI
  # Happy paths: create with required fields, edit existing, OSM event selector, optional fields blank
  # Edge cases: duplicate code inline error, missing required fields, form pre-populated on edit

  Background:
    Given the user is on the Event Form page

  # --- Happy paths ---

  Scenario: Form renders all required and optional fields
    When the create event form is opened
    Then the form contains a field for "Event Code"
    And the form contains a field for "Type"
    And the form contains a field for "Transport"
    And the form contains a field for "Level"
    And the form contains a field for "Start Date"
    And the form contains a field for "End Date"
    And the form contains a field for "Leader in Charge Name"
    And the form contains a field for "Start Location"
    And the form contains a field for "End Location"
    And the form contains a field for "OSM Event"
    And the form contains a field for "Route Planning Notes"

  Scenario: Submitting with all required fields saves the event
    When the user fills in required fields and submits the form
    Then the event is saved
    And the new event appears in the Season Dashboard

  Scenario: Optional fields can be left blank on create
    When the user fills in only required fields and leaves LiC and locations blank
    And the user submits the form
    Then the event is saved without error

  Scenario: OSM event selector lists synced events from ems_osm_events
    Given 2 OSM events are synced with names "Camp MacPherson" and "Summer Expedition"
    When the create event form is opened
    Then the OSM event dropdown contains "Camp MacPherson"
    And the OSM event dropdown contains "Summer Expedition"

  Scenario: Editing an existing event pre-populates all fields
    Given an event exists with code "H-SP1" and LiC name "Jane Smith"
    When the edit form is opened for event "H-SP1"
    Then the "Event Code" field shows "H-SP1"
    And the "Leader in Charge Name" field shows "Jane Smith"

  Scenario: Saving an edited event updates the values
    Given an event exists with code "H-SP1"
    When the user updates the "Start Location" to "Glencoe Car Park" and saves
    Then the event shows "Start Location" as "Glencoe Car Park"

  # --- Edge cases ---

  Scenario: Submitting a duplicate event code shows an inline error
    Given an event with code "H-SP1" already exists in the season
    When the user enters event code "H-SP1" and submits
    Then an inline error is shown: "Event code already exists in this season"
    And the form is not submitted

  Scenario: Submitting without an event code shows a validation error
    When the user submits the form without entering an event code
    Then a validation error is shown for "Event Code"

  Scenario: Submitting without a start date shows a validation error
    When the user submits the form without entering a start date
    Then a validation error is shown for "Start Date"

  Scenario: Submitting without an end date shows a validation error
    When the user submits the form without entering an end date
    Then a validation error is shown for "End Date"
