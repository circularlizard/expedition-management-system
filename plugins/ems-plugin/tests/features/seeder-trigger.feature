Feature: Seed Test Data from Admin UI
  As an EMS Administrator
  I want to be able to trigger test data seeding from the settings page
  So that I can easily populate the system with mock expeditions and signups for local testing

  Scenario: Seeding test data successfully
    Given the database has some synced explorers
    When the administrator is on the settings page
    And clicks "Seed Test Data"
    Then the system should delete old expeditions, teams, and submissions
    And create new test expeditions
    And generate mock form submissions for each explorer
    And display a success message "Successfully seeded"
