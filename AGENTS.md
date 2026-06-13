# EMS Agent Manifest

Entry point for AI agents working on this codebase. Read this before any other file.

## 1. Documentation Reading Order

1. `docs/Expedition Management System.md` — PRD, functional requirements, glossary
2. `docs/Technical Architecture.md` — ADRs 001–012, component diagram, shortcode registry
3. `docs/Data Schema and API.md` — CPTs, user meta, REST surface, custom tables
4. `docs/Implementation Plan Phase 1.md` — **canonical Phase 1 plan**: current state, environment, OSM integration strategy, CI/CD, Phase 1 stages (1.1–1.8), TDD tasks, acceptance criteria, source directory map
5. `docs/Implementation Plan Phase 2+.md` — **canonical Phases 2–6 plan**: Explorer/Parent views, Signup, Volunteer Management, Route Submission, OSM Push-back & Production Launch
6. `docs/OSM Oauth.md` — OSM OAuth token URLs (reference only when implementing ADR 010)

> **Archive**: Original phased plan (Phases 0–5) is preserved in `docs/archive/Development and Deployment - v1-original.md`. Phase 0 Training Report spec is in `docs/archive/Phase 0 - Training Report.md`.

## 2. Current Implementation State

Foundations are complete. The application currently has:
- OSM API Client (Mock + Live drivers, rate limiter, parser)
- OSM OIDC authentication + User Meta hydration
- Admin foundation: Settings Page, Diagnostic Panel, Reconciliation Dashboard
- `expedition` + `team` CPTs, Gravity Forms client, Reconciliation Controller
- PHP: 107 tests / 178 assertions green. JS: 8 Vitest tests green.

Next work begins at **Phase 1 — Admin Views** (`docs/Implementation Plan Phase 1.md §5`).

## 3. Task Mode Classification

- **`[offline]`** — completable using `Mock_Driver` and local Docker. No live credentials needed.
- **`[staging]`** — requires the SiteGround staging subdomain. May need OSM sandbox access.
- **`[live]`** — requires production OSM service account credentials in WP Options.

Phase-by-phase tasks, dependency ordering, and stage acceptance criteria are in `docs/Implementation Plan Phase 1.md §5` (Phase 1) and `docs/Implementation Plan Phase 2+.md` (Phases 2–6).

## 4. Environment & Credential Checklist

Items marked **[human]** require a manual step from the operator. Halt and surface a blocking prompt rather than proceeding without them.

| Item | Required From | Source |
|---|---|---|
| Docker running (`docker-compose up`) | All phases | Local |
| `EMS_TEST_MODE` constant in `wp-config.php` | All phases | `docker-compose.yml` |
| Mock payloads in `tests/mocks/` | Phase 1 | `circularlizard/OSM-Tools` repo **[human]** |
| SiteGround staging SSH access | Phase 6 | **[human]** |
| SiteGround SMTP confirmed | Phase 4 | **[human]** |
| OSM service account OAuth tokens in WP Options | Phase 6 | One-time admin setup screen **[human]** |
| `.htaccess` write access on SiteGround confirmed | Phase 5 | **[human]** |

## 5. Test Execution Commands

```bash
# PHP unit tests (from repo root)
vendor/bin/phpunit

# JS unit tests
npm run test

# E2E tests (requires staging URL set in playwright.config.ts)
npx playwright test
```
