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

  Scenario: Signups Dashboard displays breakdown by signup type, level, and expedition type
    Given the following active signups exist:
      | id | type        | scout_id | explorer_name | dofe_level | exped_type  | is_synced_osm | created_at          |
      | 10 | participant | 30003    | Mary Jones    | bronze     |             | 1             | 2026-08-03 10:00:00 |
      | 11 | participant | 0        | Alice Smith   | silver     |             | 0             | 2026-08-10 10:00:00 |
      | 12 | participant | 30002    | Bob Smith     | gold       |             | 1             | 2026-08-12 10:00:00 |
      | 20 | expedition  | 30002    | Bob Smith     | gold       | Hillwalking | 1             | 2026-08-18 10:00:00 |
      | 21 | expedition  | 30004    | Sam Taylor    | silver     | Biking      | 1             | 2026-08-20 10:00:00 |
    When the administrator opens the Signups Dashboard tab
    Then the signup type breakdown should show 3 Participant Place and 2 Expedition Preference signups
    And the DofE level breakdown should show 1 Bronze, 2 Silver, and 2 Gold signups
    And the expedition type breakdown should show 1 Hillwalking, 1 Biking, and 0 Paddling signups

  Scenario: Cumulative signups by week chart starts August 1st, displays separate lines with a legend for Participant Place and Expedition Preference, and filters by level
    Given active signups exist across Week 1 (Aug 1–7), Week 2 (Aug 8–14), and Week 3 (Aug 15–21) of the school year
    When the administrator views the Cumulative Signups by Week chart on the Signups Dashboard
    Then the chart should display two cumulative lines: one for Participant Place (total 3) and one for Expedition Preference (total 2)
    And the chart should display a legend identifying the Participant Place and Expedition Preference lines
    When the administrator filters the cumulative chart by level "silver"
    Then the cumulative chart should update both lines to count only Silver Participant Place and Silver Expedition Preference signups

  Scenario: Dashboard summarises unpaired signups (participant place without expedition and expedition without participant place) by level
    Given explorer "Mary Jones" has a Bronze participant place signup and no expedition signup
    And explorer "Alice Smith" has a Silver participant place signup and no expedition signup
    And explorer "Bob Smith" has both a Gold participant place signup and a Gold expedition signup
    And explorer "Sam Taylor" has a Silver expedition signup and no participant place signup
    When the administrator views the Signups Dashboard
    Then the unpaired signups card should show 2 explorers with a Participant Place Only (1 Bronze, 1 Silver, 0 Gold)
    And the unpaired signups card should show 1 explorer with an Expedition Only (0 Bronze, 1 Silver, 0 Gold)
    When the administrator clicks the "Participant Place Only" section
    Then the view should switch to the Signups Table filtered to those participant place records
    When the administrator clicks the "Expedition Only" section
    Then the view should switch to the Signups Table filtered to those expedition records

  Scenario: Dashboard summarises duplicate signups and records not synced from OSM
    Given explorer "Mary Jones" (scout_id 30003) has 2 active Bronze participant place signups
    And guest explorer "Alice Smith" (scout_id 0) has 1 active Silver participant place signup with no synced OSM record
    When the administrator views the Signups Dashboard
    Then the "Duplicate Signups" card should show 1 duplicate group (2 duplicate records)
    And the "No Synced OSM Record" card should show 1 unmatched signup

  Scenario: Signups Table displays Expedition Type column and filters by expedition type
    Given active signups include expedition signups for "Hillwalking" and "Biking" and participant place signups
    When the administrator switches to the Signups Table tab
    Then the table should include an "Expedition Type" column showing "Hillwalking" and "Biking" for expedition rows and "—" for participant rows
    When the administrator filters the Signups Table by Expedition Type "Hillwalking"
    Then only signups with expedition type "Hillwalking" should be displayed in the table

