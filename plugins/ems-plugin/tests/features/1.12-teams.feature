Feature: Team management within an event
  # Happy paths: create team (sequential code), delete empty team, sequential numbering maintained
  # Edge cases: team size warning, cascade delete on last member, no gaps permitted

  Background:
    Given the current user has the "manage_options" capability
    And a season exists with year "2026-27" and status "active"
    And an event exists with code "H-SP1" in the season

  # --- Happy paths ---

  Scenario: First team created for an event gets code suffix 1
    When a team is created in the event "H-SP1"
    Then the team has "ems_team_code" of "H-SP1-1"
    And the team has "ems_team_number" of 1

  Scenario: Second team created for an event gets code suffix 2
    Given a team exists with code "H-SP1-1" in event "H-SP1"
    When a team is created in the event "H-SP1"
    Then the team has "ems_team_code" of "H-SP1-2"
    And the team has "ems_team_number" of 2

  Scenario: Teams across different events are numbered independently
    Given an event exists with code "B-SP1" in the season
    And a team exists with code "H-SP1-1" in event "H-SP1"
    When a team is created in the event "B-SP1"
    Then the team has "ems_team_code" of "B-SP1-1"

  Scenario: Deleting a team with no members succeeds
    Given a team exists with code "H-SP1-1" in event "H-SP1" with 0 members
    When the team is deleted
    Then the team no longer exists

  Scenario: Team with 4 members has no size warning
    Given a team exists with code "H-SP1-1" in event "H-SP1" with 4 members
    Then the team does not have a size warning

  Scenario: Team with 7 members has no size warning
    Given a team exists with code "H-SP1-1" in event "H-SP1" with 7 members
    Then the team does not have a size warning

  # --- Edge cases ---

  Scenario: Team with 3 members triggers a size warning
    Given a team exists with code "H-SP1-1" in event "H-SP1" with 3 members
    Then the team has a size warning

  Scenario: Team with 8 members triggers a size warning
    Given a team exists with code "H-SP1-1" in event "H-SP1" with 8 members
    Then the team has a size warning

  Scenario: Size warning does not block the team from being saved
    Given a team exists with code "H-SP1-1" in event "H-SP1" with 8 members
    Then the team is persisted successfully

  Scenario: Removing the last member from a team deletes the team automatically
    Given a team exists with code "H-SP1-1" in event "H-SP1" with 1 member
    When the last member is removed from team "H-SP1-1"
    Then the team "H-SP1-1" no longer exists

  Scenario: Team numbers are renumbered when a middle team is deleted
    Given a team exists with code "H-SP1-1" in event "H-SP1"
    And a team exists with code "H-SP1-2" in event "H-SP1"
    And a team exists with code "H-SP1-3" in event "H-SP1"
    When the team "H-SP1-2" is deleted
    Then the remaining teams in event "H-SP1" have sequential numbers 1 and 2
    And no team with code "H-SP1-3" exists
    And a team with code "H-SP1-2" exists with the former "H-SP1-3" members

  Scenario: Cannot delete a team that has members via the direct delete endpoint
    Given a team exists with code "H-SP1-1" in event "H-SP1" with 2 members
    When the team is deleted directly
    Then an error is returned with code "ems_team_has_members"
    And the team still exists
