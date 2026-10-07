Feature: Team route card and GPX submissions
  As an expedition team member or leader
  I want to submit and review route cards and GPX files
  So that teams have approved navigation plans before expeditions depart

  Background:
    Given a season exists with year "2026-27" and status "active"
    And an event exists with code "H-SP1" in the season
    And a team exists with code "H-SP1-1" in event "H-SP1"

  Scenario: Submitting the initial route file assigns version 1
    When a "pdf" route file is submitted for team "H-SP1-1"
    Then the submission status is "pending"
    And the submission version is 1

  Scenario: Submitting an updated route file increments version number
    Given a "pdf" route submission exists for team "H-SP1-1" at version 1
    When a "pdf" route file is submitted for team "H-SP1-1"
    Then the submission version is 2
    And the submission status is "pending"

  Scenario: Submitting an invalid file type is rejected
    When a "docx" route file is submitted for team "H-SP1-1"
    Then the submission is rejected with an invalid file type error

  Scenario: Leader reviews route submission and updates status
    Given a "pdf" route submission exists for team "H-SP1-1" with status "pending"
    When a leader reviews the submission with status "approved" and feedback "Well planned route"
    Then the submission status is "approved"
    And the submission feedback is "Well planned route"
