Feature: Milestone 5 - OSM Push-Back Sync Write-Back (Phase 2)
  As an Administrator
  I want to push EMS changes back to Online Scout Manager
  So that OSM is kept up to date with expedition assignments, training status, and invitations.

  Background:
    Given I am authenticated as an administrator
    And the designated write-back section is configured to 101

  Scenario: Creating a flexi-record when it does not exist on OSM
    Given the write-back section 101 has no managed flexi-record ID stored
    And OSM does not have a flexi-record named "2026 Expeditions" for section 101
    When the sync engine runs write-back prep for section 101
    Then a new flexi-record named "2026 Expeditions" should be created on OSM
    And the new record ID should be stored in the EMS database

  Scenario: Adding missing columns to an existing flexi-record
    Given the write-back section 101 is linked to flexi-record ID 73848
    And the flexi-record 73848 is missing the "PRACTICE GROUPS" column
    When the sync engine runs write-back prep for section 101
    Then the column "PRACTICE GROUPS" should be created on OSM for flexi-record 73848

  Scenario: Skipping and warning about explorers not in the write-back section
    Given a team in EMS has members:
      | scout_id | name        | section_id |
      | 30001    | Alice Smith | 101        |
      | 30002    | Bob Jones   | 102        |
    When the sync engine runs write-back prep for section 101
    Then scout 30002 should be marked as skipped in the exceptions list
    And the sync preview should show a warning for scout 30002
