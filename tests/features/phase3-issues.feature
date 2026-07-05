Feature: Phase 3 Explorers Page Rebuild
  As an EMS Administrator
  I want a rebuilt Explorers page using a table/inspector layout
  So that I can view detailed explorer profile information on demand

  Scenario: Get explorer profile details successfully
    Given an explorer with scout ID 10001 exists in the database
    And the explorer belongs to a unit with leader email "leader@example.com"
    And the explorer has a participant place signup for level "Gold"
    And the explorer is assigned to a training team in event "T-EXP1"
    When the REST API request "GET /ems/v1/explorers/10001/profile" is made by an admin
    Then the response status is 200
    And the profile contains the explorer first name, last name, scout ID, and email
    And the profile contains the unit name and leader email
    And the profile contains training, practice, and qualifying event lists with OSM status
    And the profile contains expedition available dates, team preferences, and additional support needs
    And the profile contains a table of participant place signups ordered by date descending
    And the profile contains Tutor LMS training records

  Scenario: Get explorer profile returns 404 if not found
    Given no explorer with scout ID 99999 exists in the database
    When the REST API request "GET /ems/v1/explorers/99999/profile" is made by an admin
    Then the response status is 404

  Scenario: Rebuild UI with Table/Inspector Layout
    Given the administrator views the Explorers Page
    Then a list of explorers is displayed on the left
    And clicking an explorer opens the profile inspector on the right
    And the inspector shows unit leader email, training status, and participant place signups

  Scenario: Inspector pagination controls
    Given the administrator views the Explorers Page
    And the explorer list is filtered
    When the administrator opens the inspector for the first explorer
    Then the inspector header displays navigation controls
    When the administrator clicks the next button
    Then the inspector switches to the next explorer in the filtered list

  Scenario: Signups board supports inline URL parameter checking
    Given the administrator is on the Participant Places board
    When the page is loaded with URL parameter "?id=45"
    Then the inspector for signup record 45 is automatically opened
