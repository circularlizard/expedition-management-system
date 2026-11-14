# Expedition Dashboard — Current State & Next Steps

**Date:** 2026-06-24
**Status:** In progress — rendering, but data sync and UX need work.

---

## What's Working

- Board renders with seasons → expeditions → teams hierarchy
- Team creation and member addition persist to the server
- Event creation, editing, and deletion work server-side
- Explorer pool loads from `ems_osm_explorers`

---

## Bugs to Fix

### 1. Removed members permanently lost from explorer pool

**File:** `resources/js/admin/expedition-board/SeasonDashboard.tsx`
**Line:** ~329 in `removeMember`

When adding a member, the explorer is filtered out of the available pool. When removing, the code searches the *available* pool — but the explorer isn't there anymore. So `removedExplorer` is always `undefined` and the explorer is never restored.

**Fix:** Search the team's `members` array instead of the `explorers` array. Reconstruct the explorer object from the member record's `first_name`, `last_name`, `patrol`, and `scout_id`.

### 2. Deleted team members never restored to explorer pool

**File:** `resources/js/admin/expedition-board/SeasonDashboard.tsx`
**Line:** ~360-377 in `deleteTeam`

`deleteTeam` removes the team from the board but doesn't iterate over `team.members` to restore each one to `b.explorers`.

**Fix:** After finding the team in the board data, iterate `team.members` and push each one back into `b.explorers` before filtering the team out.

### 3. `SeasonDashboard` never syncs with updated `data` prop

**File:** `resources/js/admin/expedition-board/SeasonDashboard.tsx`
**Line:** 27

`useState<BoardData>(data)` captures the initial value. If `ExpeditionBoard` calls `refetch()` (after external mutations from cross-event views, move panels, etc.), the new `data` prop is never applied to `SeasonDashboard`'s internal state.

**Fix:** Add `useEffect(() => { setBoard(data); }, [data]);` or restructure so mutations always flow through `updateBoard` rather than triggering `refetch`.

### 4. `create_event` / `update_event` don't hydrate teams

**File:** `src/Admin/Expedition_Admin_Controller.php`
**Lines:** 218-219, 237-238

`get_board()` hydrates each event with `teams[]`, `member_count`, and member details. But `create_event()` and `update_event()` return raw post data from `get_by_id()` — no teams, no member counts. The UI patches with defaults (`teams: []`, `member_count: 0`), which works for new events but creates silent data divergence.

**Fix:** Extract the team/member hydration from `get_board()` into a reusable method (e.g., `hydrate_event`) and call it from `create_event()`, `update_event()`, and `get_board()`.

---

## UX Improvements

### 5. Expedition list navigation is poor

**File:** `resources/js/admin/expedition-board/SeasonDashboard.tsx`
**Component:** `EventCard` (~line 145)

**Problems:**
- **Broken icons:** `typeIcon()`, `transportIcon()`, `levelIcon()` return values that don't render properly.
- **No filter controls:** No way to filter expeditions by type (training/practice/qualifying), transport (hillwalking/biking/paddling), or level (bronze/silver/gold).
- **Monospaced event code:** The event code displays as monospace text next to the title instead of the event name being the primary heading.
- **Missing date prominence:** The date range is small and secondary rather than part of the main event heading.

**Fix:**
- Replace icon functions with reliable SVG or Unicode symbols, or use WordPress admin icons.
- Add a filter bar above the expedition list with dropdowns/toggles for type, transport, and level.
- Restructure `EventCard` header: event `post_title` as a heading, date range immediately below it, metadata (type/transport/level badges) as secondary info.

---

## Implementation Order

1. **Bug 3** — Add `useEffect` sync (simplest, unblocks testing of everything else)
2. **Bug 1** — Fix `removeMember` explorer restoration
3. **Bug 2** — Fix `deleteTeam` member restoration
4. **Bug 4** — Extract `hydrate_event` in PHP controller
5. **UX 5** — Redesign `EventCard` header + add filter controls

Bugs 1-4 are correctness issues. UX 5 is polish but affects daily usability. Do bugs first, then UX.
