# Proposal: EMS Admin Menu Restructuring

The current WordPress admin menu for the Expedition Management System (EMS) is fragmented across multiple classes and results in a "blank" top-level page. This proposal centralizes the menu registration and organizes the subpages into a logical workflow.

## 1. Proposed Hierarchy

The top-level menu will be **EMS**, and its first item (and landing page) will be the **Expedition Board**.

| Menu Item | Label | Slug | Responsibility |
| :--- | :--- | :--- | :--- |
| **Top Level** | **EMS** | `ems` | Parent Menu |
| Submenu 1 | **Expedition Board** | `ems` | Primary landing page: Team builder and overview |
| Submenu 2 | **OSM Reference** | `ems-reference` | **[NEW]** View raw explorers, events, and attendance |
| Submenu 3 | **Training Report** | `ems-training-report` | TutorLMS course completion status |
| Submenu 4 | **Flexirecord Mapper** | `ems-column-mapper` | Flexi-record configuration |
| Submenu 5 | **Settings** | `ems-settings` | API keys, Section IDs, and system config |

## 2. Key Improvements

1.  **Eliminate Blank Page**: The top-level `add_menu_page` will use the same slug as the first submenu (`ems`), ensuring that clicking the main "EMS" menu item takes the user directly to the Expedition Board.
2.  **Workflow Alignment**: Submenus are ordered by frequency of use (Daily operations -> Sync/Audit -> Configuration).
3.  **New Reference View**: Explicitly includes the **OSM Reference** page defined in the updated sync flow, allowing admins to verify OSM data independently of Flexi-records.
4.  **Centralized Registration**: Recommended to move all menu registrations to a single location (e.g., `Admin_Page::register_menu()`) or use a shared parent slug consistently.

## 3. Implementation Plan

1.  **Update `Admin_Page::register()`**:
    *   Change `add_menu_page` to use the `render_dashboard` callback instead of `__return_null`.
    *   Add the `ems-reference` submenu.
2.  **Update `Training_Report_Page::register()`**:
    *   Remove the redundant `add_menu_page` call.
    *   Ensure it hooks into the `ems` parent slug.
3.  **Update `Settings_Page::register()`**:
    *   Ensure it hooks into the `ems` parent slug.
4.  **Cleanup**: Ensure all pages use consistent styling (WP `wrap` class, H1 headers).
