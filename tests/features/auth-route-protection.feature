Feature: Access Control and Page Protection
  As a site administrator
  I want to restrict access to specific WordPress pages and Tutor LMS endpoints based on user authentication status and roles
  So that only authorized users can view sensitive portal contents.

  Background:
    Given the EMS plugin is active
    And the custom user roles "ems_explorer", "ems_parent", and "ems_leader" exist

  Scenario: Guest accesses an unprotected page
    Given the page "/contact-us/" is not protected
    When a guest user visits "/contact-us/"
    Then the page should render successfully without redirection

  Scenario: Guest accesses a protected page and is redirected to login
    Given the page "/dashboard/" is protected
    When a guest user visits "/dashboard/"
    Then they should be redirected to the OIDC login page with redirect URL "/dashboard/"

  Scenario: Authenticated user with insufficient role is denied access
    Given the page "/dashboard/" is protected
    And the allowed roles for "/dashboard/" are "ems_explorer" and "administrator"
    And a user is logged in with role "ems_parent"
    When the user visits "/dashboard/"
    Then they should receive a 403 Access Denied error page

  Scenario: Authenticated user with permitted role is allowed access
    Given the page "/dashboard/" is protected
    And the allowed roles for "/dashboard/" are "ems_explorer" and "administrator"
    And a user is logged in with role "ems_explorer"
    When the user visits "/dashboard/"
    Then the page should render successfully without redirection

  Scenario: Guest accesses a Tutor LMS course page when Tutor LMS protection is enabled
    Given Tutor LMS page protection is enabled
    And a guest user visits "/courses/bronze-navigation/"
    Then they should be redirected to the OIDC login page with redirect URL "/courses/bronze-navigation/"

  Scenario: Authenticated user with permitted role accesses Tutor LMS course page
    Given Tutor LMS page protection is enabled
    And the allowed roles for Tutor LMS pages are "ems_explorer" and "administrator"
    And a user is logged in with role "ems_explorer"
    When the user visits "/courses/bronze-navigation/"
    Then the page should render successfully without redirection
