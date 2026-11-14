Feature: System-Wide Audit Logging and Viewer
  As an Administrator
  I want all sensitive updates, configurations, views, and login actions to be audited
  So that I have an immutable trail of user activities for security compliance.

  Background:
    Given I am authenticated as an administrator

  # --- Section 1: Audit Log Generation ---

  Scenario: Audit log entry is written when assigning a team member
    When I assign explorer "30001" to team post "42"
    Then an audit log entry is created with action "team_member_add" and target scout ID "30001"

  Scenario: Audit log entry is written when manual changes are saved for an explorer
    When I update explorer "30001" first aid level to "first_response"
    Then an audit log entry is created with action "explorer_update" and target scout ID "30001"

  Scenario: Audit log entry is written when sync triggers and completes
    When I trigger the OSM data sync
    Then audit log entries are created for actions "sync_start" and "sync_success"

  Scenario: Audit log entry is written when downloading a GPX file
    When I download the GPX file for team post "42"
    Then an audit log entry is created with action "view_gpx" and target scout ID null

  # --- Section 2: REST API Security & Filtering ---

  Scenario: Unauthorized users cannot access the audit log REST endpoint
    Given I am authenticated as a parent or explorer
    When I request the audit logs via the REST API
    Then I receive a 403 Forbidden error response

  Scenario: Filter audit logs via the REST API
    Given there are audit logs for action "view_asn" and target scout "30002"
    When I request the audit logs filtered by target scout ID "30002" via the REST API
    Then the response only includes logs matching target scout ID "30002"

  # --- Section 3: Log Retention & Purging ---

  Scenario: Auto-purge logs older than 365 days and enforce maximum row limits
    Given there are audit logs dated 370 days ago
    And the audit logs table contains 50,100 records
    When the scheduled log rotation runs
    Then the audit logs older than 365 days are deleted
    And the total count of audit logs is reduced to 50,000 or fewer records
