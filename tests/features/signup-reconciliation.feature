Feature: Explorer Signup Reconciliation & Multi-Unit Validation
  As an expedition administrator
  I want to reconcile guest signups that lack a Scout ID
  And I want to ensure that only matched explorers with a valid Scout ID are assigned to expeditions
  So that data integrity is maintained and sync errors with Online Scout Manager (OSM) are prevented.

  Scenario: Prevent allocating unmatched guest signup to a team
    Given a guest signup exists in the database with ID 500 and scout_id 0 and status "submitted"
    When an admin attempts to add the member with ID 500 to a team
    Then the action should be blocked
    And a validation error "Cannot allocate unmatched explorer without a Scout ID" should be returned

  Scenario: Suggest matching synced explorers for guest signups based on email
    Given a guest signup exists with explorer email "explorer1@example.com" and scout_id 0
    And a synced explorer exists in the database with Scout ID 12345 and email "explorer1@example.com"
    When the admin requests matching suggestions for the guest signup
    Then the suggestions should include the explorer with Scout ID 12345 and name "Mary Smith"

  Scenario: Match guest signup and batch update other forms for the same explorer
    Given a guest participant signup exists with ID 500, scout_id 0, name "Mary Smith", and email "explorer1@example.com"
    And a guest expedition signup exists with ID 501, scout_id 0, name "Mary Smith", and email "explorer1@example.com"
    And a synced explorer exists with Scout ID 12345 and name "Mary Smith"
    When the admin confirms the match between signup ID 500 and explorer Scout ID 12345
    Then the participant signup 500 should be updated with scout_id 12345
    And the expedition signup 501 should be updated with scout_id 12345

  Scenario: Unlink / Undo a matched explorer signup
    Given a participant signup exists with ID 500 and scout_id 12345 (originally matched from guest)
    When the admin unlinks the signup 500
    Then the signup 500 should be updated with scout_id 0

  Scenario: Filter out archived records from the active dashboard view by default
    Given a participant signup exists with ID 500 and status "archived"
    And a participant signup exists with ID 501 and status "submitted"
    When the admin views the active signups dashboard
    Then the dashboard should display signup 501
    And the dashboard should not display signup 500

  Scenario: Downstream validation of section membership during sync preview
    Given an expedition event belongs to target OSM section 99001
    And a team is created in the expedition containing an explorer with Scout ID 12345
    And the target OSM section 99001 members list does not contain Scout ID 12345
    When the admin requests a pushback sync preview for section 99001
    Then the preview should fail validation with error "Explorer is not a member of the target OSM section"
