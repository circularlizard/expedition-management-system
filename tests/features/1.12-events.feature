Feature: Event (expedition) management within a season
  # Happy paths: create, edit, delete, link OSM event
  # Edge cases: duplicate code, delete with teams, invalid enum values, missing required fields

  Background:
    Given the current user has the "manage_options" capability
    And a season exists with year "2026-27" and status "active"

  # --- Happy paths ---

  Scenario: Create an event with all required fields
    When an event is created in the season with:
      | field          | value      |
      | ems_event_code | H-SP1      |
      | ems_type       | practice   |
      | ems_transport  | hillwalking|
      | ems_level      | silver     |
      | ems_start_date | 2027-06-01 |
      | ems_end_date   | 2027-06-03 |
    Then the event is persisted as a child of the season
    And the event has "ems_event_code" of "H-SP1"

  Scenario: Create an event with optional fields left blank
    When an event is created in the season with:
      | field          | value      |
      | ems_event_code | H-SP1      |
      | ems_type       | practice   |
      | ems_transport  | hillwalking|
      | ems_level      | silver     |
      | ems_start_date | 2027-06-01 |
      | ems_end_date   | 2027-06-03 |
    Then the event is persisted with blank "ems_lic_name"
    And the event is persisted with blank "ems_start_location"
    And the event is persisted with blank "ems_end_location"

  Scenario: Edit event fields
    Given an event exists with code "H-SP1" in the season
    When the event is updated with "ems_lic_name" set to "Jane Smith"
    And the event is updated with "ems_start_location" set to "Glencoe Car Park"
    Then the event has "ems_lic_name" of "Jane Smith"
    And the event has "ems_start_location" of "Glencoe Car Park"

  Scenario: Link an event to a synced OSM event
    Given an event exists with code "H-SP1" in the season
    And an OSM event exists with id 40001
    When the event is updated with "ems_osm_event_id" set to 40001
    Then the event has "ems_osm_event_id" of 40001

  Scenario: Delete an event that has no teams
    Given an event exists with code "H-SP1" in the season
    And the event has no teams
    When the event is deleted
    Then the event no longer exists

  Scenario: Event code is unique within a season but may repeat across seasons
    Given an event exists with code "H-SP1" in the season
    And a second season exists with year "2025-26"
    When an event is created in the second season with code "H-SP1"
    Then the event is persisted successfully

  # --- Edge cases ---

  Scenario: Cannot create an event with a duplicate code within the same season
    Given an event exists with code "H-SP1" in the season
    When an event is created in the season with code "H-SP1"
    Then an error is returned with code "ems_event_code_exists"

  Scenario: Cannot delete an event that has teams
    Given an event exists with code "H-SP1" in the season
    And the event has 1 team
    When the event is deleted
    Then an error is returned with code "ems_event_has_teams"
    And the event still exists

  Scenario: Cannot create an event with an invalid type
    When an event is created in the season with "ems_type" set to "invalid"
    Then an error is returned with code "ems_invalid_field_value"

  Scenario: Cannot create an event with an invalid transport
    When an event is created in the season with "ems_transport" set to "swimming"
    Then an error is returned with code "ems_invalid_field_value"

  Scenario: Cannot create an event without a start date
    When an event is created in the season without "ems_start_date"
    Then an error is returned with code "ems_missing_required_field"

  Scenario: Cannot create an event without an event code
    When an event is created in the season without "ems_event_code"
    Then an error is returned with code "ems_missing_required_field"
