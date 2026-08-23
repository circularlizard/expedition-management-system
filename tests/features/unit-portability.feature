Feature: Export and Import Unit Lookup Table
  As an EMS Administrator
  I want to be able to export and import the Unit lookup table separately
  So that I can transfer or back up unit configurations without touching the rest of the database

  Scenario: Exporting the Unit lookup table successfully
    Given the database has some units configured
    When the administrator is on the Unit Lookup settings page
    And clicks "Export Units (.json)"
    Then the system should generate a JSON file containing the serialized ems_units table data
    And trigger a download for the generated units backup file

  Scenario: Importing the Unit lookup table successfully
    Given a valid units lookup JSON backup file
    When the administrator is on the Unit Lookup settings page
    And uploads the units lookup JSON file
    And clicks "Import Units"
    Then the system should truncate the ems_units table
    And restore the unit records from the JSON backup file
    And display a success message "Unit lookup data imported successfully."

  Scenario: Importing an invalid units backup file fails
    Given an invalid or corrupt units lookup JSON file
    When the administrator is on the Unit Lookup settings page
    And uploads the invalid units lookup JSON file
    And clicks "Import Units"
    Then the system should not truncate the ems_units table
    And display an error message "Import failed: Invalid units backup file structure."
