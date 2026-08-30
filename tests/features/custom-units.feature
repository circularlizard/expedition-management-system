Feature: Manage Master Units
  As an EMS Administrator
  I want to be able to maintain the list of units and contacts separately from OSM sync
  So that I can configure local unit details and automatically match them to OSM patrols

  Scenario: Creating a new master unit successfully
    Given the administrator is on the Unit Lookup settings page
    When the administrator fills in "Add Master Unit" form with:
      | name         | Orion ESU                |
      | district     | Braid                    |
      | short_code   | ORION                    |
      | unit_id      | 12345                    |
      | leader_email | leader.orion@example.com |
    And clicks "Add Master Unit"
    Then a new master unit "Orion ESU" should be created in the database
    And the list of active units should include "Orion ESU"

  Scenario: Updating a master unit's details
    Given a master unit "Orion ESU" exists
    When the administrator is on the Unit Lookup settings page
    And changes the master unit name to "Orion Explorer Scout Unit"
    And changes the leader email to "new.leader@example.com"
    And changes the district to "Pentland"
    And clicks "Save Unit Leaders"
    Then the unit "Orion Explorer Scout Unit" should have the district "Pentland" and leader email "new.leader@example.com"

  Scenario: Deleting a master unit
    Given a master unit "Orion ESU" exists
    When the administrator is on the Unit Lookup settings page
    And clicks "Delete" for the master unit "Orion ESU"
    Then the master unit "Orion ESU" should be deleted from the database
    And should no longer appear in the list of active units

  Scenario: Syncing OSM patrols matches them to master units
    Given a master unit "Kelso ESU" exists with short_code "BO-Kelso" and unit_id 10
    And a master unit "Orion ESU" exists with short_code "BO-Orion" and unit_id 20
    When an OSM reference sync is triggered for patrols:
      | patrol_id | section_id | name       |
      | 1001      | 99001      | Kelso      |
      | 1002      | 99001      | BO-Orion   |
      | 1003      | 99001      | Unmatched  |
    Then the synced patrol "Kelso" should be linked to unit ID 10
    And the synced patrol "BO-Orion" should be linked to unit ID 20
    And the synced patrol "Unmatched" should have no linked unit ID
