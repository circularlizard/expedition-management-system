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
