Feature: Milestone 3 - Volunteer Staffing Console Refinement
  As an EMS Administrator
  I want to view volunteer availability, assign them to events, and enforce constraints
  So that events are safely staffed and volunteers are not overcommitted

  Scenario: Staffing view displays coverage health check
    Given the following expeditions exist:
      | ID | Title           | Type       | Level  | Start Date | End Date   |
      | 10 | Bronze Practice | practice   | bronze | 2026-08-14 | 2026-08-16 |
      | 11 | Gold Qualifier  | qualifying | gold   | 2026-08-18 | 2026-08-22 |
    And volunteer "John Doe" has pending availability on event 10
    When the administrator views the "Event Staffing" dashboard
    Then event 10 should show a "Needs Volunteers" health badge
    And event 11 should show a "Needs Volunteers" health badge

  Scenario: Assigning volunteer flags constraint violations
    Given volunteer "John Doe" has the following constraints:
      | max_practices  | 1 |
      | max_qualifiers | 0 |
      | max_total      | 1 |
    And volunteer "John Doe" is already confirmed on event 10 (Type: practice)
    When the administrator tries to assign "John Doe" to event 11 (Type: qualifying)
    Then the interface should display a warning badge "Exceeds overall limit of 1 events."
    And the assignment should be flagged as a constraint conflict

  Scenario: Coordinator overrides and edits volunteer availability
    Given volunteer "John Doe" exists
    When the administrator views the "Volunteer Registry" profile for "John Doe"
    And the administrator adds availability for event 10 on 2026-08-14
    Then the database should record a pending availability row for "John Doe" on event 10
