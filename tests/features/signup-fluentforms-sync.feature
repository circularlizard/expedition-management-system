Feature: Fluent Forms Signup Sync & Unit Lookup Integration

  As an expedition administrator
  I want Fluent Forms submissions to be dynamically mapped and saved to the signups database table
  So that parent signups are correctly linked to explorers, ESU units, and payment states.

  Scenario: Dynamic pre-population of children options and default unit
    Given a parent user is logged in with child mappings for Scout ID 30001 named "Mary Smith" in section 99001
    And ESU patrol mapping exists for section 99001 named "Kelso" (short code "BO-Kelso") with unit ID 10
    When the signup form is rendered
    Then the dropdown choices for "signup_child" should include "Mary Smith" with value "30001"
    And the default/pre-populated unit selection should be "BO-Kelso" (resolved from child section ID)

  Scenario: Dynamic pre-population of self-signup choices for explorer/network member
    Given an explorer or network user is logged in with Scout ID 30001 named "Tom Strachan" in section 99001
    And ESU patrol mapping exists for section 99001 named "Kelso" (short code "BO-Kelso") with unit ID 10
    When the signup form is rendered
    Then the dropdown choices for "signup_child" should include "Tom Strachan" with value "30001"
    And the default/pre-populated unit selection should be "BO-Kelso" (resolved from own section ID)

  Scenario: Explorer submits valid self-signup form
    Given an explorer user is logged in with Scout ID 30001 named "Tom" "Strachan"
    And ESU patrol mapping exists with short code "BO-Kelso" and unit ID 10
    And form field mappings exist for form ID 4
    When a form submission is inserted for form ID 4 with values:
      | signup_child              | 30001                                                |
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
      | explorer_first_name       | Tom                                                  |
      | explorer_last_name        | Strachan                                             |
      | dofe_level                | bronze                                               |
    And the database signup record "expedition_preferences" JSON should contain:
      | exped_type                | Hillwalking                                          |

  Scenario: Parent submits valid signup form
    Given a parent user is logged in with child mappings for Scout ID 30001
    And ESU patrol mapping exists with short code "BO-Kelso" and unit ID 10
    And form field mappings exist for form ID 4
    When a form submission is inserted for form ID 4 with values:
      | signup_child              | 30001                                                |
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
    When the form submits option value "99999" (unlinked to parent)
    Then the submission should fail validation with an ownership error

  Scenario: Guest submits valid signup form
    Given a guest user (logged out)
    And form field mappings exist for form ID 4
    When a form submission is inserted for form ID 4 with values:
      | signup_child              |                                                      |
      | signup_child_name         | {"first_name": "James", "last_name": "Guest"}        |
      | signup_unit               | BO-Kelso                                             |
      | signup_level              | Bronze                                               |
      | exped_type                | Hillwalking                                          |
    Then a signup record should be created in the database with:
      | scout_id                  | 0                                                    |
      | parent_user_id            | 0                                                    |
      | explorer_first_name       | James                                                |
      | explorer_last_name        | Guest                                                |
      | dofe_level                | bronze                                               |

  Scenario: Explorer submits valid signup form with numeric unit ID
    Given an explorer user is logged in with Scout ID 30001 named "Tom" "Strachan"
    And ESU patrol mapping exists with short code "BO-Kelso" and unit ID 10
    And form field mappings exist for form ID 4
    When a form submission is inserted for form ID 4 with values:
      | signup_child              | 30001                                                |
      | signup_unit               | 10                                                   |
      | signup_level              | Bronze                                               |
      | exped_type                | Hillwalking                                          |
    Then a signup record should be created in the database with:
      | scout_id                  | 30001                                                |
      | parent_user_id            | 1                                                    |
      | unit_id                   | 10                                                   |
      | explorer_first_name       | Tom                                                  |
      | explorer_last_name        | Strachan                                             |
      | dofe_level                | bronze                                               |

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

  Scenario: Frontend shortcode rendering does not overwrite backend settings
    Given the admin has configured "ems_fluent_participant_form_id" as 6
    And the admin has configured "ems_fluent_expedition_form_id" as 3
    When the signup banner shortcode is rendered with attributes:
      | type    | expedition |
      | form_id | 6          |
    Then the option "ems_fluent_participant_form_id" should remain 6
    And the option "ems_fluent_expedition_form_id" should remain 3

  Scenario: Dynamic leader email mapping is prepared for frontend unit changes
    Given master units exist in the unit table with short codes, names, unit IDs, and leader emails
    When the form interaction script is enqueued
    Then the unit mappings map should include entries for short codes, names, and unit IDs pointing to their respective leader emails

  Scenario: Bronze expedition submission captures expedition type from exped_type_bronze and updated form fields
    Given a parent user is logged in with a linked child having Scout ID 30001
    And ESU patrol mapping exists with short code "BO-Kelso"
    And expedition form field mappings exist for form ID 3
    When an expedition form submission is inserted for form ID 3 with values:
      | signup_child                | 30001                                                |
      | signup_child_name           | {"first_name": "Tom", "last_name": "Strachan"}       |
      | signup_dob                  | 15/05/2011                                           |
      | signup_email                | tom@example.com                                      |
      | signup_parentname           | {"first_name": "Jane", "last_name": "Strachan"}      |
      | signup_parent_email         | jane@example.com                                     |
      | signup_unit                 | BO-Kelso                                             |
      | signup_leader_email         | leader@kelso.scouts.org.uk                           |
      | signup_level                | Bronze                                               |
      | signup_edofe_registered     | yes                                                  |
      | signup_dofe_number          | 1234567                                              |
      | exped_type                  |                                                      |
      | exped_type_bronze           | Paddling                                             |
      | exped_bronze_dates          | ["B-PQ1", "B-PQ2"]                                   |
      | exped_team_names            | Sam Taylor                                           |
      | input_radio                 | first-aid-yes                                        |
      | input_radio_1               | Yes                                                  |
      | description                 | Dyslexia                                             |
    Then an expedition signup record should be created in the database with:
      | scout_id                    | 30001                                                |
      | dofe_level                  | bronze                                               |
      | dob                         | 2011-05-15                                           |
      | dofe_registered             | yes                                                  |
      | dofe_number                 | 1234567                                              |
      | first_aid_status            | first-aid-yes                                        |
      | additional_support_needs    | Dyslexia                                             |
    And the expedition signup preferences JSON should contain:
      | exped_type                  | Paddling                                             |
      | exped_practice_dates        | ["B-PQ1", "B-PQ2"]                                   |
      | exped_qualifier_dates       | ["B-PQ1", "B-PQ2"]                                   |
      | exped_team_names            | Sam Taylor                                           |

  Scenario: Silver and Gold expedition submissions capture underscored practice and qualifier date fields
    Given a parent user is logged in with a linked child having Scout ID 30002
    And expedition form field mappings exist for form ID 3
    When an expedition form submission is inserted for form ID 3 with values:
      | signup_child                    | 30002                                            |
      | signup_child_name               | {"first_name": "Lucy", "last_name": "Strachan"}  |
      | signup_unit                     | BO-Kelso                                         |
      | signup_level                    | Silver                                           |
      | signup_edofe_registered         | Registered but not received yet                  |
      | exped_type                      | Hillwalking                                      |
      | exped_silver_practice_dates     | ["H-SP1", "H-SP2"]                               |
      | exped_silver_qualifier_dates    | ["H-SQ1"]                                        |
      | input_radio                     | first-aid-no                                     |
      | input_radio_1                   | No                                               |
    Then an expedition signup record should be created in the database with:
      | scout_id                        | 30002                                            |
      | dofe_level                      | silver                                           |
      | dofe_registered                 | Registered but not received yet                  |
      | first_aid_status                | first-aid-no                                     |
    And the expedition signup preferences JSON should contain:
      | exped_type                      | Hillwalking                                      |
      | exped_practice_dates            | ["H-SP1", "H-SP2"]                               |
      | exped_qualifier_dates           | ["H-SQ1"]                                        |

  Scenario: Re-syncing existing signups from Settings re-parses Fluent Forms submissions with current field mappings
    Given an existing expedition signup record exists with form_submission_id 501 and signup_status "submitted"
    And Fluent Forms submission 501 exists for form ID 3 with JSON response containing updated fields:
      | signup_child                | 30001                                                |
      | signup_dob                  | 15/05/2011                                           |
      | signup_email                | tom@example.com                                      |
      | signup_level                | Bronze                                               |
      | signup_edofe_registered     | yes                                                  |
      | exped_type_bronze           | Paddling                                             |
      | exped_bronze_dates          | ["B-PQ1", "B-PQ2"]                                   |
      | description                 | Dyslexia                                             |
    When an administrator triggers "Re-sync Signups from Fluent Forms" in Settings -> Form Mappings
    Then the existing expedition signup record for form_submission_id 501 should be updated with:
      | explorer_email              | tom@example.com                                      |
      | dob                         | 2011-05-15                                           |
      | dofe_registered             | yes                                                  |
      | additional_support_needs    | Dyslexia                                             |
    And its expedition_preferences JSON should contain exped_type "Paddling" and exped_practice_dates '["B-PQ1", "B-PQ2"]'

  Scenario: Re-syncing archives EMS signups whose Fluent Forms submission or form has been deleted
    Given an existing expedition signup record exists with form_submission_id 502 and signup_status "submitted"
    And an existing participant signup record exists with form_submission_id 503 and signup_status "submitted"
    And Fluent Forms submission 502 has been deleted from wp_fluentform_submissions
    And Fluent Forms submission 503 belongs to a deleted form or has status "trashed"
    When an administrator triggers "Re-sync Signups from Fluent Forms" in Settings -> Form Mappings
    Then the expedition signup record for form_submission_id 502 should have signup_status "archived"
    And the participant signup record for form_submission_id 503 should have signup_status "archived"
    And the Settings page should display a notice summarizing the number of updated and archived signups



