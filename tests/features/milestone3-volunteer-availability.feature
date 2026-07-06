Feature: Milestone 3 - Adult Volunteer Availability Mapping
  As an EMS Administrator or a Volunteer
  I want to register and manage adult volunteer availability and assignments
  So that expeditions are safely staffed and double-booking is avoided

  Scenario: Volunteer submits guest signup wizard
    When a guest volunteer submits availability for event 10 (dates: 2026-08-14 to 2026-08-16)
    Then a record should be created in the ems_volunteers table
    And 3 rows should be created in ems_volunteer_availability with confirmed = 0

  Scenario: Volunteer signs in via OSM OAuth
    Given the volunteer initiates OSM sign-in
    When the OSM OAuth2 token is verified
    Then the signup wizard should pre-populate contact details
    And retrieve previous availability submissions if they exist

  Scenario: Guest volunteer signup fails with invalid contact details
    When a guest volunteer attempts to submit signup without email or first name
    Then the wizard should display validation errors
    And no database records should be created

  Scenario: Volunteer duplicates availability across similar multi-day blocks
    Given the volunteer has configured shift availability for a date block
    When the volunteer triggers the "Cross-Event Copy" action
    Then that shift configuration should be copied to other selected multi-day blocks

  Scenario: Overlapping events choose either option inserts pending rows
    Given event A (Highland Trek) and event B (Mourne Mountains) run concurrently on 2026-08-14
    When a volunteer selects "either" event during signup
    Then pending availability records (confirmed = 0) should be created for both event A and event B

  Scenario: Confirming overlapping event locks out alternative assignment
    Given a volunteer has pending availability for event A and event B on 2026-08-14
    When the administrator confirms the volunteer on event A
    Then the availability record for event A should be confirmed = 1
    And the availability record for event B should automatically set confirmed = -1 (Conflicted)

  Scenario: Unassigning overlapping event releases schedule lock
    Given a volunteer has confirmed = 1 on event A and confirmed = -1 on event B for 2026-08-14
    When the administrator unassigns the volunteer from event A
    Then the availability record for event B should reset to confirmed = 0 (Pending)
