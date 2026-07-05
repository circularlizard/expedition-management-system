Feature: Phase 1 Issues Resolution
  As an EMS Administrator
  I want prior completions, styles, and form mappings to be correctly handled
  So that registrations are accurately sync'd and visual displays are clear

  Scenario: Supporting array format for prior level completions
    Given a participant signup exists with dofe_level "silver" and bronze_completion array '["Volunteering", "Skills"]'
    When the administrator views the participant signups board
    Then the UI should display completion badges for "V" and "S"
    And should not display completion badges for "P" or "E"

  Scenario: Supporting legacy object format for prior level completions
    Given a participant signup exists with dofe_level "silver" and bronze_completion object '{"volunteering":"completed", "physical":"completed"}'
    When the administrator views the participant signups board
    Then the UI should display completion badges for "V" and "P"
    And should not display completion badges for "S" or "E"

  Scenario: Showing a red X emoji when no prior completions exist
    Given a participant signup exists with dofe_level "silver" and bronze_completion array '["None"]'
    When the administrator views the participant signups board
    Then the UI should display the "❌" emoji with no circle for prior level status

  Scenario: Customizing full Fluent Form mappings in settings page
    Given the administrator is on the settings page
    When the administrator updates the Form Mappings for the Expedition Signup form
    And sets "silver_practice_dates_field" to "exped-silver-practice-dates"
    And sets "gold_practice_dates_field" to "exped-gold-practice-dates"
    Then the configured mappings are saved to the database option "ems_expedition_form_mappings"

  Scenario: Dynamically resolving dates fields on Fluent Forms submission
    Given form mappings option "ems_expedition_form_mappings" defines:
      | silver_practice_dates_field  | exped-silver-practice-dates |
      | silver_qualifier_dates_field | exped-silver-qualifier-dates |
    When a form submission is processed for Silver level with:
      | signup_level                 | Silver                      |
      | exped-silver-practice-dates  | ["P-01-07"]                 |
      | exped-silver-qualifier-dates | ["Q-15-08"]                 |
    Then the saved signup record preferences should contain practice dates ["P-01-07"] and qualifier dates ["Q-15-08"]
