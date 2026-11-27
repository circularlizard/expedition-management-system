Feature: Milestone 5 - OSM Push-Back Sync Write-Back (Phase 2)
  As an Administrator
  I want to push EMS event attendance invitations back to Online Scout Manager
  So that OSM events are kept up to date with expedition invitations.

  Background:
    Given I am authenticated as an administrator
    And the designated write-back section is configured to 101

  Scenario: Skipping and warning about explorers not in the write-back section
    Given a team in EMS has members:
      | scout_id | name        | section_id |
      | 30001    | Alice Smith | 101        |
      | 30002    | Bob Jones   | 102        |
    When the sync engine runs write-back prep for section 101
    Then scout 30002 should be marked as skipped in the exceptions list
    And the sync preview should show a warning for scout 30002
