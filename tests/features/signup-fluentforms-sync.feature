Feature: Fluent Forms Signup Sync & Unit Lookup Integration

  As an expedition administrator
  I want Fluent Forms submissions to be dynamically mapped and saved to the signups database table
  So that parent signups are correctly linked to explorers, ESU units, and payment states.

  Scenario: Dynamic pre-population of children options and default unit
    Given a parent user is logged in with child mappings for Scout ID 30001 named "Mary Smith" in section 99001
    And ESU patrol mapping exists for section 99001 named "Kelso" (short code "BO-Kelso") with unit ID 10
    When the signup form is rendered
    Then the dropdown choices for "signup_child" should include "Mary Smith" with value "30001|Mary|Smith"
    And the default/pre-populated unit selection should be "BO-Kelso" (resolved from child section ID)

  Scenario: Parent submits valid signup form
    Given a parent user is logged in with child mappings for Scout ID 30001
    And ESU patrol mapping exists with short code "BO-Kelso" and unit ID 10
    And form field mappings exist for form ID 4
    When a form submission is inserted for form ID 4 with values:
      | signup_child              | 30001|Mary|Smith                                  |
      | signup_unit               | BO-Kelso                                             |
      | signup_level              | Bronze                                               |
      | input_radio               | first-response                                       |
      | exped_type                | Hillwalking                                          |
      | exped_practice_dates      | ["P-28-6"]                                           |
      | exped_qualifier_dates     | ["Q-13-8"]                                           |
      | exped_team_names          | "John Doe"                                           |
      | exped_asn                 | "None"                                               |
    Then a signup record should be created in the database with:
      | scout_id                  | 30001                                                |
      | parent_user_id            | 1                                                    |
      | unit_id                   | 10                                                   |
      | explorer_first_name       | Mary                                                 |
      | explorer_last_name        | Smith                                                |
      | dofe_level                | bronze                                               |
      | first_aid_status          | first-response                                       |
      | signup_status             | pending                                              |
      | payment_status            | pending                                              |
    And the database signup record "expedition_preferences" JSON should contain:
      | exped_type                | Hillwalking                                          |
      | exped_practice_dates      | ["P-28-6"]                                           |

  Scenario: Prevent parent from submitting a child they do not own
    Given a parent user is logged in with child mappings for Scout ID 30001
    And form field mappings exist for form ID 4
    When the form submits option value "99999|John|Doe" (unlinked to parent)
    Then the submission should fail validation with an ownership error

  Scenario: Stripe payment success updates signup record to paid
    Given a signup record exists in the database for submission entry ID 1234
    When a Stripe payment status updated hook is triggered for entry ID 1234 with status "paid"
    Then the signup record payment status in the database should be updated to "paid"

  Scenario: Async payment in processing state leaves signup as pending
    Given a signup record exists in the database for submission entry ID 1234 with payment_status "pending"
    When a Stripe payment status updated hook is triggered for entry ID 1234 with status "processing"
    Then the signup record payment status in the database should remain "pending"

  Scenario: A paid signup is not downgraded by a late processing event
    Given a signup record exists in the database for submission entry ID 1234 with payment_status "paid"
    When a Stripe payment status updated hook is triggered for entry ID 1234 with status "processing"
    Then the signup record payment status in the database should remain "paid"
