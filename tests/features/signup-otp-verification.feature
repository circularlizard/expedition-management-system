Feature: Fluent Forms In-Form OTP Email Verification

  As an expedition administrator
  I want guest parents to verify their email addresses in-form via One-Time Passcodes (OTP)
  So that we can guarantee email ownership without forcing high-friction email confirmation links,
  while bypassing checks for OIDC-verified parent emails, and deduplicating checks for identical addresses.

  Scenario: Guest parent successfully verifies email and submits form
    Given a guest user (logged out)
    And a secure verification OTP transient exists for "parent@example.com" on field "signup_parent_email" with value "123456"
    And form field mappings exist for form ID 4
    When a form submission is inserted for form ID 4 with values:
      | signup_parent_email       | parent@example.com                                   |
      | signup_parent_otp_code    | 123456                                               |
      | signup_child_name         | {"first_name": "James", "last_name": "Guest"}        |
    Then the validation check should succeed
    And the verification OTP transient for "parent@example.com" should be deleted

  Scenario: Guest parent enters incorrect OTP code
    Given a guest user (logged out)
    And a secure verification OTP transient exists for "parent@example.com" on field "signup_parent_email" with value "123456"
    And form field mappings exist for form ID 4
    When a form submission is inserted for form ID 4 with values:
      | signup_parent_email       | parent@example.com                                   |
      | signup_parent_otp_code    | 999999                                               |
      | signup_child_name         | {"first_name": "James", "last_name": "Guest"}        |
    Then the validation check should fail with error "The verification code is incorrect."

  Scenario: Guest parent submits form without requesting OTP code
    Given a guest user (logged out)
    And form field mappings exist for form ID 4
    When a form submission is inserted for form ID 4 with values:
      | signup_parent_email       | parent@example.com                                   |
      | signup_parent_otp_code    | 123456                                               |
      | signup_child_name         | {"first_name": "James", "last_name": "Guest"}        |
    Then the validation check should fail with error "The verification code has expired or was not requested."

  Scenario: Logged-in parent bypasses parent OTP validation
    Given a parent user is logged in with user email "parent@example.com"
    And form field mappings exist for form ID 4
    When a form submission is inserted for form ID 4 with values:
      | signup_parent_email       | parent@example.com                                   |
      | signup_parent_otp_code    |                                                      |
      | signup_child              | 30001                                                |
    Then the validation check should succeed

  Scenario: Guest parent uses same email for parent and explorer, bypassing second OTP check
    Given a guest user (logged out)
    And a secure verification OTP transient exists for "family@example.com" on field "signup_parent_email" with value "123456"
    And form field mappings exist for form ID 4
    When a form submission is inserted for form ID 4 with values:
      | signup_parent_email       | family@example.com                                   |
      | signup_parent_otp_code    | 123456                                               |
      | signup_explorer_email     | family@example.com                                   |
      | signup_explorer_otp_code  |                                                      |
    Then the validation check should succeed
    And the verification OTP transient for "family@example.com" should be deleted

  Scenario: Guest parent decides to change a verified email address
    Given a guest user (logged out)
    And a parent email "parent@example.com" has been verified with code "123456"
    When the user requests to change their verified email address
    Then the parent email field "signup_parent_email" should be editable
    And the parent email verification status should be invalidated
    And the parent OTP verification code should be cleared from cached state
