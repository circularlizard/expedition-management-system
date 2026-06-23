Feature: Season management
  # Happy paths: create season, list seasons, archive season
  # Edge cases: duplicate year, archive non-existent, list when empty

  Background:
    Given the current user has the "manage_options" capability

  # --- Happy paths ---

  Scenario: Create a new season
    When a season is created with year "2026-27" and status "active"
    Then the season is persisted with title "2026-27 Season"
    And the season has "ems_season_year" of "2026-27"
    And the season has "ems_season_status" of "active"

  Scenario: List all seasons returns newest first
    Given a season exists with year "2025-26" and status "archived"
    And a season exists with year "2026-27" and status "active"
    When all seasons are listed
    Then the response contains 2 seasons
    And the first season has year "2026-27"

  Scenario: Archive a season
    Given a season exists with year "2026-27" and status "active"
    When the season is archived
    Then the season has "ems_season_status" of "archived"

  # --- Edge cases ---

  Scenario: Cannot create two seasons with the same year
    Given a season exists with year "2026-27" and status "active"
    When a season is created with year "2026-27" and status "active"
    Then an error is returned with code "ems_season_year_exists"

  Scenario: Listing seasons when none exist returns empty array
    Given no seasons exist
    When all seasons are listed
    Then the response contains 0 seasons

  Scenario: Archiving a season that does not exist returns an error
    When a non-existent season is archived
    Then an error is returned with code "ems_season_not_found"
