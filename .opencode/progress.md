## Goal
- Refactor `SeasonDashboard.tsx` to use CSS classes instead of inline styles.

## Constraints & Preferences
- **(none)**

## Progress
### Done
- Added SeasonDashboard CSS classes to `ems-admin.css`.
- Replaced all inline styles in `SeasonDashboard.tsx` with CSS classes.
- All 89 JS tests pass.
- All 337 PHP tests pass.
- Built and deployed via `bin/deploy.sh`.
- Committed and pushed: `53b6093`

### In Progress
- (none)

### Blocked
- (none)

## Key Decisions
- Defined CSS classes for filter bars, season cards, event headers, dialogs, team columns, member lists, and all UI elements in `ems-admin.css`.
- Used BEM-like naming convention (`ems-team-column__header`, `ems-team-column__actions`, etc.).

## Next Steps
- Task complete.

## Critical Context
- All inline styles in `SeasonDashboard.tsx` have been replaced with CSS classes.
- Pre-existing LSP errors in `EventForm.tsx` and `SignupsBoard.tsx` are unrelated to this work.
