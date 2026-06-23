Feature: Expedition Board REST endpoint — season/event/team hierarchy

  The expedition board endpoint returns data structured as a season
  containing events, each event containing teams, each team containing
  its members. This is the primary data feed for the Season Dashboard UI.

  Background:
    Given the current user has the "manage_options" capability

  Scenario: Board returns events grouped under their season
    Given a season exists with title "2026-27 Season" and status "active"
    And an event exists with code "H-SP1" under that season
    When a GET request is made to "ems/v1/expedition-board"
    Then the response status is 200
    And the response contains a "seasons" array
    And the first season has a "events" array containing the event with code "H-SP1"

  Scenario: Each event in the board response includes its teams
    Given a season exists with title "2026-27 Season" and status "active"
    And an event exists with code "H-SP1" under that season
    And a team exists with code "H-SP1-1" under that event
    When a GET request is made to "ems/v1/expedition-board"
    Then the response status is 200
    And the event with code "H-SP1" has a "teams" array containing the team with code "H-SP1-1"

  Scenario: Each team in the board response includes its member count
    Given a season exists with title "2026-27 Season" and status "active"
    And an event exists with code "H-SP1" under that season
    And a team exists with code "H-SP1-1" under that event with 3 members
    When a GET request is made to "ems/v1/expedition-board"
    Then the team with code "H-SP1-1" has a "member_count" of 3

  Scenario: Team outside official size range is flagged with a warning
    Given a season exists with title "2026-27 Season" and status "active"
    And an event exists with code "H-SP1" under that season
    And a team exists with code "H-SP1-1" under that event with 8 members
    When a GET request is made to "ems/v1/expedition-board"
    Then the team with code "H-SP1-1" has "size_warning" set to true

  Scenario: Team within official size range has no warning
    Given a season exists with title "2026-27 Season" and status "active"
    And an event exists with code "H-SP1" under that season
    And a team exists with code "H-SP1-1" under that event with 5 members
    When a GET request is made to "ems/v1/expedition-board"
    Then the team with code "H-SP1-1" has "size_warning" set to false

  Scenario: Board returns empty seasons array when no seasons exist
    Given no seasons exist
    When a GET request is made to "ems/v1/expedition-board"
    Then the response status is 200
    And the response contains a "seasons" array with 0 items

  Scenario: Board returns season with empty events array when season has no events
    Given a season exists with title "2026-27 Season" and status "active"
    And no events exist under that season
    When a GET request is made to "ems/v1/expedition-board"
    Then the response status is 200
    And the first season has an empty "events" array

  Scenario: Unauthenticated request is rejected
    Given the current user is not authenticated
    When a GET request is made to "ems/v1/expedition-board"
    Then the response status is 401
