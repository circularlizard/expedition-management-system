Feature: Milestone 1 - Signups Board & Reconciliation

  Scenario: Fuzzy matching maps signups to explorers by email
    Given a synced explorer exists with scout_id 101 and email "jane@example.com"
    And a signup exists with email "jane@example.com" and no scout_id
    When the administrator fetches signups list
    Then the signup record linkage_status should be "proposed"
    And the proposed_scout_id should be 101

  Scenario: Manually reconciling links signup to scout_id
    Given a synced explorer exists with scout_id 102
    And a signup exists with ID 10 and scout_id null
    When the administrator manually reconciles signup 10 to scout_id 102
    Then signup 10 scout_id should be 102
    And reconciled_by should match the current admin user ID

  Scenario: Processing copies DofE number to synced explorer record
    Given a synced explorer exists with scout_id 103 and dofe_number null
    And a paid signup exists with ID 11, scout_id 103, and dofe_number "D-882233"
    When the administrator processes signup 11
    Then signup 11 signup_status should be "processed"
    And explorer 103 dofe_number should be "D-882233"
    And processed_by should match the current admin user ID
