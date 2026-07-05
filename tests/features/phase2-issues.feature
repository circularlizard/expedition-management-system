Feature: Phase 2 Issues Resolution
  As an EMS Administrator
  I want UI improvements, styling, and navigation enhancements across the Event dashboard, Planner, and Details views
  So that event management is efficient and details are clear

  Scenario: Upcoming events dashboard list has an Edit button
    Given the administrator is on the Season Dashboard
    Then each event row in the upcoming events list should display an "Edit" button next to "Archive"
    When the administrator clicks the "Edit" button for an event
    Then the administrator is redirected to the event detail page
    And the Overview tab is automatically opened in edit mode

  Scenario: Event Planner availability roster shows explorer team preferences
    Given the administrator views the Event Planning Board
    Then the explorer availability roster table should contain a "Team Preferences" column
    And the column should show the explorer's preferred teams from their signup

  Scenario: Rename Notes to Route Information in Overview tab
    When the administrator views the event detail Overview tab
    Then the notes section header is labeled "Route Information"
    And the field label in the event edit form is labeled "Route Information"

  Scenario: Formatting team card participant counts in headers
    When the administrator views the event detail Teams tab
    Then the team card headers display the participant count as " (count)" format next to the team code

  Scenario: Moving an explorer updates team dropdown list instantly
    Given the administrator is on the Teams tab
    And a new team is created
    When the administrator opens the Move dropdown on a member card
    Then the dropdown choices should contain the newly created team immediately without page reload

  Scenario: Add Member list is sorted alphabetically
    Given the administrator is on the Teams tab
    When the administrator clicks "Add Member" on a team card
    Then the list of available explorers in the dropdown is sorted alphabetically by name

  Scenario: Teams tab shows First Aid symbols legend
    When the administrator views the event detail Teams tab
    Then the header shows a legend explaining "⊕ Full First Aid" and "✚ First Response" symbols
