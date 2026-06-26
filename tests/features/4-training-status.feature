Feature: Training status on expedition view

  As an administrator or leader
  I want to see the training status of each team member on the expedition view
  So that I can verify they have completed the required training for the event level.

  Background:
    Given training requirements are configured:
      | level  | courses            |
      | bronze | Course A, Course B |
      | silver | Course A, Course B, Course C |
    And an explorer "Bob Smith" exists with scout ID 30002 and wp_user_id 10
    And an explorer "Alice MacLeod" exists with scout ID 30001 and wp_user_id 11
    And an explorer "Charlie Brown" exists with scout ID 30003 and no wp_user_id set

  Scenario: Member has completed all required training
    Given "Bob Smith" has completed "Course A" and "Course B"
    And "Bob Smith" is in a team for a "bronze" expedition
    When the expedition board is loaded
    Then "Bob Smith" training status is marked as complete
    And there are no training gaps listed for "Bob Smith"

  Scenario: Member is missing required training
    Given "Alice MacLeod" has completed "Course A" but not "Course B"
    And "Alice MacLeod" is in a team for a "bronze" expedition
    When the expedition board is loaded
    Then "Alice MacLeod" training status is marked with a warning
    And the training gaps for "Alice MacLeod" list "Course B"

  Scenario: Unlinked member shows no training status
    Given "Charlie Brown" is in a team for a "bronze" expedition
    When the expedition board is loaded
    Then "Charlie Brown" training status is displayed as "—"
