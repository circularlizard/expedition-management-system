# Expedition Management System - Unit Mapping Report

This report documents the results of matching the units in [wp_ems_units.csv](file:///Users/davidstrachan/Projects/expedition-management-system/mockdata/wp_ems_units.csv) against the section definitions from OSM in [sectionLookup.json](file:///Users/davidstrachan/Projects/expedition-management-system/mockdata/sectionLookup.json) and [sectionList.txt](file:///Users/davidstrachan/Projects/expedition-management-system/mockdata/sectionList.txt).

The populated export file has been saved to:
👉 [wp_ems_units_populated.csv](file:///Users/davidstrachan/Projects/expedition-management-system/mockdata/wp_ems_units_populated.csv)

---

## 📊 Mapping Summary

- **Total Units in Input**: 41
- **Successfully Mapped**: 31
- **Unmatched / Ambiguous**: 10

---

## 1. Mapped Units (31)

Below is the list of units that were successfully mapped to their OSM `section_id`:

| CSV ID | CSV Unit Name | Matches OSM Section | Section ID | Source / Rationale |
|---|---|---|---|---|
| 2 | `CR-CRAMOND` | `Cramond Explorers` | **8631** | `sectionList.txt` match |
| 3 | `CR-PINK PANTHERS` | `Pink Panthers ESU` | **16500** | `sectionList.txt` match |
| 4 | `CR-DOUGLAS BROWN` | `Douglas Brown Explorers` | **2282** | `sectionList.txt` match |
| 5 | `BR-MEADOWS` | `Meadows ESU` | **22556** | Active `explorers` ESU in Braid District |
| 6 | `BO- LAUDERDALE` | `Explorers and Young Leaders` | **21551** | `sectionList.txt` match |
| 7 | `EL-NORTH BERWICK` | `1st North Berwick Explorers` | **49940** | Active `explorers` ESU in East Lothian |
| 8 | `BR-SMESU` | `SMESU (South Morningside ESU)` | **32620** | Active `explorers` ESU in Braid District |
| 9 | `PE-BORE STANE` | `Bore Stane ESU` | **37458** | Already populated in source CSV |
| 11 | `CR-SOUTH QUEENSFERRY` | `Explorers` | **20147** | `sectionList.txt` match |
| 13 | `ML- GOREBRIDGE` | `Explorers` | **27415** | Active `explorers` ESU in Gorebridge group |
| 14 | `EL-EAST LINTON` | `East Linton Explorer Unit` | **31125** | `sectionList.txt` match |
| 15 | `BR-GREENBANK` | `GESU (Greenbank ESU)` | **54559** | Active `explorers` ESU in Braid District |
| 16 | `BR-BRAID YL` | `Braid Young Leaders` | **23683** | Active `explorers` ESU in Braid District |
| 17 | `PE- WILDFIRE` | `Wildfire Explorers` | **22552** | Active `explorers` ESU in Pentland District |
| 19 | `BO-TWEEDGLEN` | `Tweed Glen ESU` | **14777** | `sectionList.txt` match |
| 20 | `PE - DEPEVAC` | `DEPEVAK ESU` | **38653** | Active `explorers` ESU in Pentland District |
| 21 | `ENE-CALTON` | `Calton Explorers` | **47611** | Active `explorers` ESU in Calton group |
| 22 | `EL-Young Leaders` | `East Lothian Young Leaders` | **63928** | `sectionList.txt` match |
| 24 | `PE-TORMAIN` | `Tormain ESU` | **57842** | Active `explorers` ESU in Pentland District |
| 25 | `PE-TOWER` | `Tower ESU` | **63986** | Active `explorers` ESU in Pentland District |
| 26 | `EL-HADDINGTON` | `Haddington Explorers` | **68863** | `sectionList.txt` match |
| 28 | `BO-YLs` | `Young Leader ESU` | **82906** | Active `explorers` ESU in Borders District |
| 29 | `EL-WAGGONWAY` | `Waggonway Explorer Unit` | **55083** | Active `explorers` ESU in East Lothian |
| 30 | `BO-KELSO` | `Explorers` | **74302** | `sectionList.txt` match |
| 33 | `BR-MOLACH-VIKINGS` | `Molach Vikings` | **78865** | Active `explorers` ESU in Braid District |
| 34 | `EL-DUNBAR` | `1st Dunbar Explorers DofE` | **68800** | `sectionList.txt` match |
| 36 | `PE-CASTLE` | `Castle ESU` | **76164** | Active `explorers` ESU in Pentland District |
| 37 | `ENE - LINKS` | `Explorers (and Network)` | **69727** | `sectionList.txt` match |
| 38 | `CR-Young Leaders` | `Young Leaders` | **43706** | `sectionList.txt` match |
| 39 | `PE-EDGE` | `Edge ESU` | **31256** | Active `explorers` ESU in Pentland District |
| 41 | `PE-YOUNG LEADERS` | `Young Leaders` | **28752** | Active `explorers` ESU in Pentland District |

---

## 2. Unmatched / Ambiguous Units (10)

These units were not matched to avoid guessing. Their information is either entirely missing from the OSM dump or too generic to resolve uniquely:

| CSV ID | CSV Unit Name | Reason for No Match |
|---|---|---|
| 1 | `Leaders` | Generic name; matches multiple adult/leader records. Not an active explorer unit. |
| 10 | `BR-ALBATROSS` | ESU name "Albatross" does not exist in any format within [sectionLookup.json](file:///Users/davidstrachan/Projects/expedition-management-system/mockdata/sectionLookup.json). |
| 12 | `ML-BONNYRIGG` | Group/District block for Bonnyrigg (14th Midlothian) is entirely missing from the lookup files. |
| 18 | `NETWORK PATROL` | Generic term; multiple active Network units exist in different districts (e.g. Pentland, Lauderdale, East Lothian). |
| 23 | `CR-Graeme Allan` | ESU name "Graeme Allan" does not exist in [sectionLookup.json](file:///Users/davidstrachan/Projects/expedition-management-system/mockdata/sectionLookup.json). |
| 27 | `EL-CRAIGENTINNY` | ESU name "Craigentinny" does not exist in [sectionLookup.json](file:///Users/davidstrachan/Projects/expedition-management-system/mockdata/sectionLookup.json). |
| 31 | `CR-CHARLIE BROON` | ESU name "Charlie Broon" does not exist in [sectionLookup.json](file:///Users/davidstrachan/Projects/expedition-management-system/mockdata/sectionLookup.json). |
| 32 | `DofeContacts` | Administrative entry/list, does not correspond to an explorer unit/section. |
| 35 | `CR-GRANTON` | ESU name "Granton" does not exist in [sectionLookup.json](file:///Users/davidstrachan/Projects/expedition-management-system/mockdata/sectionLookup.json). |
| 40 | `XX-TEST` | Test/dummy section; matches only inactive or test sections. |
