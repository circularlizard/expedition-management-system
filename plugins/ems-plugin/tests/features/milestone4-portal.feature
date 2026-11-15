Feature: Milestone 4 - Explorer & Parent Front-Facing Web Portal

  Scenario: Unauthenticated visitor sees login CTA
    Given the current visitor is not logged in
    When they access the [ems-portal] page
    Then they should see the Online Scout Manager login prompt

  Scenario: Logged-in Explorer sees their own timeline and event details
    Given I am logged in as a user with the role "ems_explorer"
    And my scout_id is 30001
    When I access the [ems-portal] page
    Then I should see my active expedition timeline
    And the teammates list should show "Alice S. (Falcons)" but hide Alice's email address

  Scenario: Logged-in Parent selects child and checks status
    Given I am logged in as a user with the role "ems_parent"
    And I have children with scout_ids [30001, 30002]
    When I access the [ems-portal] page
    Then I should see profile cards for my children

  Scenario: Logged-in Parent views unsynced child details with form submissions
    Given I am logged in as a user with the role "ems_parent"
    And I have children with scout_ids [30003]
    And explorer with scout_id 30003 is not synced from OSM
    And explorer with scout_id 30003 has a participant signup with level "silver" and status "received"
    When they access the explorer details endpoint for scout_id 30003
    Then the response should be successful
    And the response should include the participant signup with level "silver"
    And the explorer name should be resolved from user metadata or signups

  Scenario: Logged-in Explorer (unsynced) views own details with form submissions
    Given I am logged in as a user with the role "ems_explorer"
    And my scout_id is 30003
    And explorer with scout_id 30003 is not synced from OSM
    And explorer with scout_id 30003 has an expedition signup with level "gold" and status "pending"
    When they access the portal session details
    Then they should see a profile for scout_id 30003
    When they access the explorer details endpoint for scout_id 30003
    Then the response should be successful
    And the response should include the expedition signup with level "gold"

  Scenario: Logged-in Administrator uses selector to preview portal as a participant or parent
    Given I am logged in as an administrator
    When I access the [ems-portal] page
    Then I should see the admin preview selector bar
    When I select a parent or explorer from the preview selector
    Then the portal should display that user's profile and expedition details

  Scenario: Regular Parent does not see admin preview controls
    Given I am logged in as a user with the role "ems_parent"
    When I access the [ems-portal] page
    Then I should not see any admin preview controls

  Scenario: Regular Explorer does not see admin preview controls
    Given I am logged in as a user with the role "ems_explorer"
    When I access the [ems-portal] page
    Then I should not see any admin preview controls
