Feature: Milestone 5 - OSM Push-Back Sync Preview
  As an Administrator
  I want to preview the proposed changes before they are pushed back to OSM
  So that I can verify data alignment without mutating live OSM records.

  Background:
    Given I am authenticated as an administrator

  Scenario: Previewing invitations for scouts with unset attendance
    Given a section exists with ID 101
    And an event exists with ID 40001 linked to expedition post 10
    And a team exists with members:
      | scout_id | name        | local_status |
      | 30001    | Alice Smith | assigned     |
    And the current OSM event attendance for event 40001 has:
      | member_id | attending |
      | 30001     | null      |
    When I request the sync preview for section 101
    Then the response should include a proposed invitation for scout 30001 on event 40001

  Scenario: Existing OSM event attendance status is preserved
    Given a section exists with ID 101
    And an event exists with ID 40001 linked to expedition post 10
    And a team exists with members:
      | scout_id | name        | local_status |
      | 30001    | Alice Smith | assigned     |
    And the current OSM event attendance for event 40001 has:
      | member_id | attending |
      | 30001     | yes       |
    When I request the sync preview for section 101
    Then the response should not propose any invitation changes for scout 30001 on event 40001

  Scenario: Fallback when expedition has no OSM event ID
    Given a section exists with ID 101
    And an expedition post 10 exists with no ems_osm_event_id
    And a team exists with members:
      | scout_id | name        |
      | 30001    | Alice Smith |
    When I request the sync preview for section 101
    Then the response should skip event attendance preview for expedition 10

  Scenario: Previewing flexi-record value changes
    Given a section exists with ID 101
    And a managed flexi-record exists with ID 99848
    And the current OSM flexi-record data for 99848 has:
      | scoutid | f_9 (PRACTICE GROUPS) |
      | 30001   | HGP1-1                |
    And scout 30001 is assigned to local team "HGP1-2" in EMS (practice)
    When I request the sync preview for section 101
    Then the response should propose updating flexi-record 99848 column f_9 for scout 30001 to "HGP1-2"
