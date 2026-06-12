# EMS Agent Manifest

Entry point for AI agents working on this codebase. Read this before any other file.

## 1. Documentation Reading Order

1. `docs/Expedition Management System.md` — PRD, functional requirements, glossary
2. `docs/Phase 0 - Training Report.md` — Phase 0 spec: local dev, CI/deploy pipeline, Tutor LMS report
3. `docs/Technical Architecture.md` — ADRs 001–012, component diagram, shortcode registry
4. `docs/Data Schema and API.md` — CPTs, user meta, REST surface, custom tables
5. `docs/Development and Deployment.md` — **canonical implementation plan**: phases, TDD tasks, acceptance criteria, progress status, source directory map
6. `docs/OSM Oauth.md` — OSM OAuth token URLs (reference only when implementing ADR 010)

## 2. Task Mode Classification

- **`[offline]`** — completable using `Mock_Driver` and local Docker. No live credentials needed.
- **`[staging]`** — requires the SiteGround staging subdomain. May need OSM sandbox access.
- **`[live]`** — requires production OSM service account credentials in WP Options.

Phase-by-phase tasks, dependency ordering, and progress status are in `docs/Development and Deployment.md §4`.

## 3. Environment & Credential Checklist

Items marked **[human]** require a manual step from the operator. Halt and surface a blocking prompt rather than proceeding without them.

| Item | Required From | Source |
|---|---|---|
| Docker running (`docker-compose up`) | Phase 0 | Local |
| `EMS_TEST_MODE` constant in `wp-config.php` | Phase 0 | `docker-compose.yml` |
| Mock payloads in `tests/mocks/` | Phase 1 | `circularlizard/OSM-Tools` repo **[human]** |
| SiteGround staging SSH access | Phase 4 | **[human]** |
| SiteGround SMTP confirmed | Phase 4 | **[human]** |
| OSM service account OAuth tokens in WP Options | Phase 5 | One-time admin setup screen **[human]** |
| `.htaccess` write access on SiteGround confirmed | Phase 5 | **[human]** |

## 4. Test Execution Commands

```bash
# PHP unit tests (from repo root)
vendor/bin/phpunit

# JS unit tests
npm run test

# E2E tests (requires staging URL set in playwright.config.ts)
npx playwright test
```
