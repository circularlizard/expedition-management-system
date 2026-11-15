Feature: Centralized Email Templates Hub & RTE Shortcode Picker

  Scenario: Admin views all registered email templates organized by category in a Master-Detail hub
    Given the Email Templates registry contains templates for "Unit Leaders", "Signups & Chasers", and "System"
    When the administrator opens EMS -> Email Templates
    Then the left sidebar should list templates grouped by category without horizontal tabs
    And selecting "OSM Share Request" should display its Subject, Reply-To, HTML Body RTE, and available shortcodes

  Scenario: Admin edits, saves, and resets an email template via REST API
    When the administrator updates the "osm_share_request" template with a custom subject and body
    Then GET /ems/v1/email-templates should return the updated subject and body
    When the administrator clicks "Reset to Default" for "osm_share_request"
    Then POST /ems/v1/email-templates/osm_share_request/reset should restore the default subject and body

  Scenario: Admin inserts a shortcode into the RTE and sends a test email
    Given the "osm_share_request" template is selected
    When the administrator inserts "{{explorer_list_osm levels=\"bronze\"}}" from the shortcode picker
    Then the HTML Body should contain "{{explorer_list_osm levels=\"bronze\"}}"
    When the administrator clicks "Send Test Email" to "admin@example.com"
    Then POST /ems/v1/email-templates/osm_share_request/test should send an HTML email via wp_mail with sample tokens substituted
