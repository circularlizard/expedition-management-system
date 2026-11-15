Feature: REST API — request/response shape, auth, and error codes
  # Covers all admin endpoints in Data Schema §3.3
  # Happy paths: correct shape and status codes
  # Edge cases: auth rejection, not found, method not allowed

  Background:
    Given the current user has the "manage_options" capability

  # --- Seasons ---

  Scenario: POST /seasons returns 201 with the created season
    When a POST request is made to "ems/v1/seasons" with body {"year": "2026-27"}
    Then the response status is 201
    And the response body contains "ems_season_year" of "2026-27"
    And the response body contains "ems_season_status" of "active"

  Scenario: POST /seasons without manage_options returns 403
    Given the current user does not have the "manage_options" capability
    When a POST request is made to "ems/v1/seasons" with body {"year": "2026-27"}
    Then the response status is 403

  # --- Events ---

  Scenario: POST /events returns 201 with the created event
    Given a season exists with id 10
    When a POST request is made to "ems/v1/events" with a valid event body for season 10
    Then the response status is 201
    And the response body contains "ems_event_code"
    And the response body contains "ems_type"

  Scenario: PATCH /events/{id} returns 200 with updated event
    Given an event exists with id 20
    When a PATCH request is made to "ems/v1/events/20" updating "ems_lic_name" to "Jane Smith"
    Then the response status is 200
    And the response body contains "ems_lic_name" of "Jane Smith"

  Scenario: PATCH /events/{id} for non-existent event returns 404
    When a PATCH request is made to "ems/v1/events/99999" updating "ems_lic_name" to "Jane Smith"
    Then the response status is 404

  Scenario: DELETE /events/{id} with no teams returns 200
    Given an event exists with id 20 and no teams
    When a DELETE request is made to "ems/v1/events/20"
    Then the response status is 200

  Scenario: DELETE /events/{id} with teams returns 409
    Given an event exists with id 20 with 1 team
    When a DELETE request is made to "ems/v1/events/20"
    Then the response status is 409
    And the response error code is "ems_event_has_teams"

  # --- Teams ---

  Scenario: POST /events/{id}/teams returns 201 with auto-generated code
    Given an event exists with id 20 and code "H-SP1" and no teams
    When a POST request is made to "ems/v1/events/20/teams"
    Then the response status is 201
    And the response body contains "ems_team_code" of "H-SP1-1"

  Scenario: DELETE /teams/{id} with no members returns 200
    Given a team exists with id 30 and no members
    When a DELETE request is made to "ems/v1/teams/30"
    Then the response status is 200

  Scenario: DELETE /teams/{id} with members returns 409
    Given a team exists with id 30 with 2 members
    When a DELETE request is made to "ems/v1/teams/30"
    Then the response status is 409
    And the response error code is "ems_team_has_members"

  # --- Team members ---

  Scenario: POST /teams/{id}/members returns 201 with updated member list
    Given a team exists with id 30 and no members
    And an explorer "Alice MacLeod" exists in the OSM reference data
    When a POST request is made to "ems/v1/teams/30/members" with body {"scout_id": <alice_scout_id>}
    Then the response status is 201

  Scenario: DELETE /teams/{id}/members/{scout_id} returns 200
    Given a team exists with id 30 with "Alice MacLeod" as a member
    And the team has at least one other member
    When a DELETE request is made to "ems/v1/teams/30/members/<alice_scout_id>"
    Then the response status is 200

  # --- Team move and duplicate ---

  Scenario: PATCH /teams/{id}/move returns 200 with new team code
    Given a team exists with id 30 in event "H-SP1" of type "practice"
    And a target event exists with id 40 of type "practice" and code "H-SP2"
    When a PATCH request is made to "ems/v1/teams/30/move" with body {"target_event_id": 40}
    Then the response status is 200
    And the response body contains "ems_team_code" of "H-SP2-1"

  Scenario: POST /teams/{id}/duplicate returns 201 with new team
    Given a team exists with id 30 in event "H-SP1"
    And a target event exists with id 40 with code "H-SQ1"
    When a POST request is made to "ems/v1/teams/30/duplicate" with body {"target_event_id": 40}
    Then the response status is 201
    And the response body contains "ems_team_code" of "H-SQ1-1"

  # --- Explorer move ---

  Scenario: PATCH /explorers/{scout_id}/move-team returns 200
    Given "Alice MacLeod" is in team 30 in event "H-SP1" of type "practice"
    And a target team exists with id 31 in event "H-SP1"
    When a PATCH request is made to "ems/v1/explorers/<alice_scout_id>/move-team" with body {"target_team_id": 31}
    Then the response status is 200

  Scenario: PATCH /explorers/{scout_id}/move-team to incompatible event type returns 422
    Given "Alice MacLeod" is in team 30 in event "H-SP1" of type "practice"
    And a target team exists with id 40 in event "H-SQ1" of type "qualifying"
    When a PATCH request is made to "ems/v1/explorers/<alice_scout_id>/move-team" with body {"target_team_id": 40}
    Then the response status is 422
    And the response error code is "ems_incompatible_event_type"
