Feature: Manage Custom Units
  As an EMS Administrator
  I want to be able to create, update, and delete custom/manual units that are not synced from Online Scout Manager (OSM)
  So that I can configure local units or external sections alongside OSM-synced units

  Scenario: Creating a new custom unit successfully
    Given the administrator is on the Unit Lookup settings page
    When the administrator fills in "Add Custom Unit" form with:
      | name       | Orion ESU               |
      | short_code | ORION                   |
      | unit_id    | 12345                   |
      | email      | leader.orion@example.com|
    And clicks "Add Custom Unit"
    Then a new custom unit "Orion ESU" should be created in the database
    And the list of active units should include "Orion ESU"

  Scenario: Updating a custom unit including its name
    Given a custom unit "Orion ESU" exists
    When the administrator is on the Unit Lookup settings page
    And changes the custom unit name to "Orion Explorer Scout Unit"
    And changes the leader email to "new.leader@example.com"
    And clicks "Save Unit Leaders"
    Then the unit "Orion Explorer Scout Unit" should have the leader email "new.leader@example.com"

  Scenario: Deleting a custom unit
    Given a custom unit "Orion ESU" exists
    When the administrator is on the Unit Lookup settings page
    And clicks "Delete" for the custom unit "Orion ESU"
    Then the custom unit "Orion ESU" should be deleted from the database
    And should no longer appear in the list of active units
