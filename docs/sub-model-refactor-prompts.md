# EMS Style Refactoring: Phase 2 and Phase 3 Execution Guide for Sub-Models

This document contains self-contained, structured prompts for a smaller LLM (e.g., Qwen 27B/72B with a 64k context) to execute the remaining style refactoring tasks. Each step targets specific files and outlines the exact inline styles to be removed, the classes to be applied, and the expected outcomes.

---

## Global Context & Coding Standards (Always Include This in Prompts)

- **Tech Stack**: WordPress Plugin, PHP backend, React + TypeScript frontend built using Vite.
- **Styling Rules**:
  - Do NOT use `@wordpress/components` due to the dual-React import conflict (Error #321).
  - Use standard WordPress admin classes (`.button`, `.button-primary`, `.notice`, etc.) wherever appropriate.
  - Use custom CSS utility classes starting with `ems-` defined in `resources/css/ems-admin.css`.
  - Use CSS variables for theme-colors (e.g., `var(--wp-admin-theme-color, #2271b1)`).
  - Preserve inline `style={{...}}` only for strictly data-driven values that cannot be expressed statically (e.g., percentages, coordinates, dynamic color variables).
  - Ensure all modified files compile cleanly without TypeScript errors.

---

# Phase 2: Dashboards & Navigation (127 Inline Style Occurrences)

## Step 2.1: Refactor `EventsDashboard.tsx` ✅ DONE

**Target File**: `resources/js/admin/expedition-board/EventsDashboard.tsx`
**Status**: Completed 2025-07-04. Commit: `2b04c6d`.

### Objective
Remove all structural inline styles and helper style functions from the component and replace them with appropriate classes from `ems-admin.css`.

### Tasks
1. **Helper Style Functions**:
   - Replace the `tabStyle(tab)` function (lines 130-139) with a class-based approach. Use `.ems-tab-nav__button` and a modifier `.ems-tab-nav__button--active` to control the bottom border, color, and font weight.
2. **Layout Wrappers & Headers**:
   - Line 144: Replace `style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}` with classes `ems-flex-between ems-mb-16`.
   - Line 145: Replace `style={{ margin: 0, fontSize: '20px', color: '#1d2327' }}` with class `ems-dashboard-title`.
   - Line 158: Replace the inline style on the new event form container with `className="ems-new-event-card"`. Remove `style={{ margin: '0 0 16px', fontSize: '16px' }}` from the `<h3>` header; use `.ems-new-event-card h3` or a dedicated class.
3. **Navigation Tabs**:
   - Line 170: Replace `style={{ display: 'flex', borderBottom: '1px solid #dcdcde', marginBottom: '16px', alignItems: 'center', gap: '0' }}` with class `ems-tab-nav`.
   - Line 177: Replace the `<label>` inline style with a class (e.g., `.ems-tab-nav__checkbox-label` or equivalent flex/alignment classes).
4. **Loading State**:
   - Line 190: Replace `style={{ textAlign: 'center', padding: '40px', color: '#50575e' }}` with class `ems-loading-state`.
   - Line 191: Remove `style={{ float: 'none', display: 'inline-block' }}` from the spinner (the `.spinner` class should be sufficient, or use `.ems-spinner`).
5. **No Events Found Notice**:
   - Line 201: Replace `style={{ marginTop: '10px' }}` with a standard margin utility class (e.g., `.ems-mt-10`).
6. **Event Table & Rows**:
   - Line 215: Remove `style={{ marginTop: 0 }}` from the `<table>`.
   - Line 224, 225: Remove `style={{ textAlign: 'center' }}` from `<th>` and use `.ems-table-cell--center`.
   - Line 234: Replace `style={{ cursor: 'pointer', opacity: event.ems_status === 'archived' ? 0.6 : 1 }}` by adding class `ems-row-hoverable` and conditionally adding `ems-table-row--archived` when `event.ems_status === 'archived'`.
   - Line 240: Replace `style={{ fontWeight: 600, textDecoration: 'none', color: '#2271b1' }}` on the link with a class (e.g., `.ems-table__link` or similar).
   - Line 245: Replace `style={{ fontSize: '11px', fontStyle: 'italic', color: '#666', marginLeft: '6px' }}` with a class `.ems-meta-text` or similar.
   - Line 250: Replace `style={{ background: '#f0f0f0', padding: '2px 6px', borderRadius: '3px', fontSize: '12px' }}` on the `<code>` element with class `ems-code-badge`.

### Verification
- Run `npm run test` to verify no existing tests break.
- Run `npx vitest run tests/js/EventForm.test.tsx` to verify form integration is unaffected.

---

## Step 2.2: Refactor `ExpeditionView.tsx` ✅ DONE

**Target File**: `resources/js/admin/expedition-board/ExpeditionView.tsx`
**Status**: Completed 2026-07-04. Commit: `2dade53`.

### Objective
Eliminate inline styles on metadata fields, lists of team members, training requirements, and status badges.

### Tasks
1. **Container & Header**:
   - Replace the outermost panel wrapper's inline styles with `className="ems-expedition-panel"`.
   - Replace the title `<h3>` and code `<span>` inline styles with `.ems-expedition-title` and `.ems-expedition-code`.
2. **Metadata Rows & Fields**:
   - Replace the key-value field wrappers with `.ems-meta-field`, `.ems-meta-field__label`, and `.ems-meta-field__value`.
3. **Route Status Badges**:
   - Replace the inline styles on the route status badge span with `.ems-route-badge` and conditional modifier classes: `.ems-route-badge--approved` and `.ems-route-badge--rejected`.
4. **First Aid Legend & Icons**:
   - Replace the legend wrapper's inline style with `.ems-fa-legend`.
   - Replace inline styles on First Aid status icons with `.ems-fa-full` and `.ems-fa-response`.
5. **Team Table**:
   - Standardize table columns and alignments using `.ems-table` and text alignment helper classes (`.ems-table-cell--center`, `.ems-table-cell--right`).

### Verification
- Run `npx vitest run tests/js/ExpeditionView.test.tsx` to ensure all assertions hold.

---

## Step 2.3: Refactor `OSMReference.tsx` ✅ DONE

**Target File**: `resources/js/admin/expedition-board/OSMReference.tsx`
**Status**: Completed 2026-07-04. Commit: `b382145`.

### Objective
Clean up the inline styles within the OSM member synchronization and reference table.

### Tasks
1. **Container & Filter Bar**:
   - Replace the top-level container inline styles with `.ems-osm-ref-container`.
   - Replace the filter bar inline styles with `.ems-osm-ref-filter-bar`.
2. **Table Header & Sorting**:
   - Replace the sortable column headers' inline styles with `.ems-osm-ref-col-header`.
   - Replace the sort arrow indicators' inline styles with `.ems-osm-ref-col-sort` and opacity modifiers `.ems-osm-ref-col-sort--active` / `.ems-osm-ref-col-sort--inactive`.
3. **Avatar/Status Circles**:
   - Replace inline styles for the status indicators (colors and rounded borders) with `.ems-avatar-circle` and color modifiers (e.g., `.ems-avatar-circle--red`, `.ems-avatar-circle--green`, etc.).

### Verification
- Run `npx vitest run tests/js/OSMReference.test.tsx`.

---

## Step 2.4: Refactor `EventPlanningBoard.tsx`

**Target File**: `resources/js/admin/expedition-board/EventPlanningBoard.tsx`

### Objective
Remove any remaining inline styles on the main planning board view.

### Tasks
1. **Toolbar Overrides**:
   - Replace the toolbar container's inline styles with `.ems-planning-toolbar`.
2. **Spinner & Loading States**:
   - Replace the spinner wrapper's inline styles with `.ems-planning-spinner`.
3. **Empty States**:
   - Replace the "no explorers" message or empty state description inline styles with `.ems-planning-empty`.
4. **Roster Split & Columns**:
   - Ensure the sidebar split uses `.ems-split` and its sub-classes properly without manual flex percentages or fixed widths.

### Verification
- Run `npx vitest run tests/js/EventPlanningBoard.test.tsx`.

---

# Phase 3: Heavy UI Screens (272 Inline Style Occurrences)

## Step 3.1: Refactor `SeasonDashboard.tsx`

**Target File**: `resources/js/admin/expedition-board/SeasonDashboard.tsx`

### Objective
This is a complex component with many nested loops and states. Carefully extract all layout-related inline styles into CSS.

### Tasks
1. **Season Filter Bar**:
   - Replace the filter bar wrapper's inline style with `.ems-season-filter-bar`.
2. **Season Card & Header**:
   - Replace the card container's inline style with `.ems-season-card`.
   - Replace the header row's inline style with `.ems-season-header`.
3. **Event Card & Meta Row**:
   - Replace the event card wrapper's inline style with `.ems-event-card-wrapper`.
   - Replace the event card header's inline style with `.ems-event-card-header` and level-based background modifiers (`.ems-event-header--bronze`, `.ems-event-header--silver`, `.ems-event-header--gold`).
   - Replace the metadata rows with `.ems-event-meta-row`.
4. **Team Grid & Columns**:
   - Replace the team column wrappers' inline styles with `.ems-team-column` and `.ems-team-column__header`.
   - Replace the team size warning's inline style with `.ems-size-warning`.

### Verification
- Run `npx vitest run tests/js/SeasonDashboard.test.tsx` to verify the dashboard continues to render and interact correctly.

---

## Step 3.2: Refactor `EventDetailPage.tsx`

**Target File**: `resources/js/admin/expedition-board/EventDetailPage.tsx`

### Objective
This is the heaviest UI screen in the app. Ensure all sidebar team panels, member lists, warning banners, section headers, and metadata fields are refactored to use classes.

### Tasks
1. **Detail Section Headers & Notes**:
   - Replace section titles with `.ems-detail-sec-header`.
   - Replace description/notes with `.ems-detail-notes`.
2. **Links & Loading States**:
   - Replace link elements with `.ems-detail-link`.
   - Replace inline styles for loading/error indicators with `.ems-detail-link-loading` and `.ems-detail-link-error`.
3. **Team Panels & Members (Sidebar)**:
   - Replace the team panel container inline style with `.ems-team-panel`. Use the modifier `.ems-team-panel--warning` for teams with under-minimum or over-maximum numbers.
   - Replace the team header wrapper with `.ems-team-panel__header`.
   - Replace the member list rows with `.ems-team-member`. Use `.ems-empty-member` for empty slots.

### Verification
- Compile assets (`npm run build`) and verify there are no TypeScript or syntax errors.

---

## Step 3.3: Refactor `SignupsBoard.tsx`

**Target File**: `resources/js/admin/signups-board/SignupsBoard.tsx`

### Objective
Migrate the datagrid headers, filter pills, level selector, skill badges, avatar circles, and table layout within the signups board.

### Tasks
1. **Container & Toolbar**:
   - Replace the top-level container style with `.ems-signups-container` and the main content area with `.ems-signups-main`.
   - Replace the toolbar wrapper's inline style with `.ems-signups-toolbar`.
2. **Level Select & Filter Pills**:
   - Replace the level filter wrapper with `.ems-signups-level-filter` and the select dropdown with `.ems-signups-level-select`.
   - Ensure the filter pills use `.ems-filter-pill` and `.ems-filter-pill--active`.
3. **Avatars & Completed Lists**:
   - Replace the avatar wrappers and completed list containers with `.ems-signups-avatar-wrap` and `.ems-signups-completed-list`.
   - Map skill indicators to `.ems-skill-badge` and the status indicators to `.ems-avatar-circle` with appropriate color modifiers.

### Verification
- Run `npx vitest run tests/js/SignupsBoard.test.tsx`.

---

# Verification & Deployment Workflow (End of Each Step)

After making the code edits for a step:
1. **Lint Verification**:
   Ensure no syntax or linter errors are introduced.
2. **Run Tests**:
   - Run the specific vitest test suite for the component.
   - Run all JS tests: `npm run test`
   - Run all PHP tests: `vendor/bin/phpunit`
3. **Build & Deploy**:
   Run `bash bin/deploy.sh` to compile assets, copy them to the local WordPress installation, and verify that the plugin remains active without errors.
4. **Git Commit**:
   Commit the changes using a descriptive commit message following the convention:
   `<type>(<scope>): <description>` (e.g., `refactor(style): migrate inline styles in EventsDashboard to CSS classes`).
5. **Git Push**:
   Push the changes to the remote repository.
