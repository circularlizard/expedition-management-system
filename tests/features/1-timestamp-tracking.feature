Feature: Explorer update timestamp tracking

  # Business logic: local edits to an explorer stamp last_local_update_at
  # Schema: tested directly in PHPUnit against Table_Installer (not Gherkin)
  # UI: explorer table renders synced_at and last_local_update_at

  Background:
    Given an explorer "Alice MacLeod" exists in the OSM reference data with scout ID 30001
    And "Alice MacLeod" has a synced_at timestamp of "2026-06-13 20:00:00"
    And "Alice MacLeod" has a last_local_update_at value of null

  # --- Business logic: local edit stamps timestamp ---

  Scenario: Updating first aid level stamps last_local_update_at
    When "Alice MacLeod" has her first aid level changed to "first_response"
    Then "Alice MacLeod" has a non-null last_local_update_at timestamp

  Scenario: Updating first aid level does not change synced_at
    When "Alice MacLeod" has her first aid level changed to "first_response"
    Then "Alice MacLeod" synced_at remains "2026-06-13 20:00:00"

  Scenario: Multiple local edits update last_local_update_at each time
    When "Alice MacLeod" has her first aid level changed to "first_response"
    And "Alice MacLeod" has her first aid level changed to "full_first_aid"
    Then "Alice MacLeod" has a non-null last_local_update_at timestamp

  # --- UI: explorer reference table ---

  Scenario: Explorer reference table shows last synced date
    Given the explorer reference data table is rendered
    And "Alice MacLeod" has a synced_at of "2026-06-13 20:00:00"
    Then the explorer row for "Alice MacLeod" displays the synced date "2026-06-13"

  Scenario: Explorer reference table shows last local update when present
    Given the explorer reference data table is rendered
    And "Alice MacLeod" has a last_local_update_at of "2026-06-20 10:30:00"
    Then the explorer row for "Alice MacLeod" displays the local update date "2026-06-20"

  Scenario: Explorer reference table shows blank local update when null
    Given the explorer reference data table is rendered
    And "Alice MacLeod" has a last_local_update_at value of null
    Then the explorer row for "Alice MacLeod" displays a dash for local update
