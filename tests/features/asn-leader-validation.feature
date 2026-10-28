Feature: Additional Support Needs (ASN) Leader Validation & Planner Refinements

  As an expedition leader or administrator
  I want to review and validate additional support needs on expedition signups
  So that leaders have clear confirmation of support requirements across signups and planning views

  Scenario: Expedition planner does not display the first aid flag
    Given an explorer "Alice Smith" is registered for expedition planning with first aid status "first_response"
    When the administrator opens the Expedition Planner
    Then the explorer "Alice Smith" should not display a "FA" badge

  Scenario: Signups table displays dedicated ASN column with status pills
    Given the following expedition signups exist:
      | explorer_name | additional_support_needs | asn_validated |
      | Alice Smith   | Requires inhaler         | null          |
      | Bob Jones     | No                       | 0             |
      | Charlie Brown | Mild asthma              | 1             |
      | David Clark   |                          | null          |
    When the administrator views the Signups Table
    Then the table should have a dedicated "ASN" column
    And "Alice Smith" should have an amber ASN pill with a query mark
    And "Bob Jones" should have a red ASN pill with a cross
    And "Charlie Brown" should have a green ASN pill with a tick
    And "David Clark" should show "—" in the ASN column

  Scenario: Updating ASN validation status from the Expedition Signup Inspector Panel
    Given an expedition signup exists with ID 42, support needs "Requires dietary support", and asn_validated null
    When the administrator opens the Inspector Panel for signup 42
    And selects "Validated" for leader validation
    Then signup 42 asn_validated should update to 1
    And an inline success checkmark should display beside the validation field

  Scenario: Expedition planner displays amber pill for null validation and green pill for validated ASN
    Given active explorers exist in the cohort planning matrix:
      | explorer_name | additional_support_needs | asn_validated |
      | Alice Smith   | Requires inhaler         | null          |
      | Bob Jones     | No                       | 0             |
      | Charlie Brown | Mild asthma              | 1             |
      | David Clark   |                          | null          |
    When the administrator views the Cohort Planning Matrix
    Then "Alice Smith" should display an amber ASN pill
    And "Charlie Brown" should display a green ASN pill
    And "Bob Jones" should not display an ASN pill
    And "David Clark" should not display an ASN pill

  Scenario: Clicking explorer name in expedition planner opens leader validation dialogue
    Given an explorer "Alice Smith" with support needs "Requires inhaler" and asn_validated null exists in the matrix
    When the administrator clicks the name "Alice Smith" in the matrix table
    Then a leader validation dialogue should open displaying "Requires inhaler"
    When the administrator clicks "Validated" in the dialogue
    Then the explorer's ASN validation should be saved as validated
    And the dialogue should close
    And "Alice Smith" should now display a green ASN pill
