# EMS Agent Manifest

Entry point for AI agents working on this codebase. Read this before any other file.

## 1. Documentation Reading Order

1. `docs/Expedition Management System.md` — PRD, functional requirements, glossary
2. `docs/Technical Architecture.md` — ADRs 001–012, component diagram, shortcode registry
3. `docs/Data Schema and API.md` — CPTs, user meta, REST surface, custom tables
4. `docs/Development and Deployment.md` — phases, TDD tasks, acceptance criteria, source directory map
5. `docs/OSM Oauth.md` — OSM OAuth token URLs (reference only when implementing ADR 010)

## 2. Task Mode Classification

- **`[offline]`** — completable using `Mock_Driver` and local Docker. No live credentials needed.
- **`[staging]`** — requires the SiteGround staging subdomain. May need OSM sandbox access.
- **`[live]`** — requires production OSM service account credentials in WP Options.

### Phase 1 — Infrastructure & Test Setup
| Task                                                                                 | Mode        |
| --------------------------------------------------------------------------------------| -------------|
| Configure Docker environment                                                         | `[offline]` |
| Setup PHPUnit and Vitest runners                                                     | `[offline]` |
| Write `OSM_API_Client` parsing tests (red)                                           | `[offline]` |
| Implement `Mock_Driver` with payloads from `tests/mocks/`                            | `[offline]` |
| Prototype Section Participant Pull                                                   | `[offline]` |
| Implement `Auth_Provider` interface + `LoginWithGoogle_Auth_Provider` adapter        | `[offline]` |
| Implement `Mock_Auth_Provider` (static OSM payload for offline auth-dependent tests) | `[offline]` |
| Write rate limiting tests for `OSM_API_Client` (red)                                 | `[offline]` |
| Implement token-bucket rate limiting in `OSM_API_Client`                             | `[offline]` |
| Fix `$_SESSION` bug in `OSM_Auth_Integration`                                        | `[offline]` |

### Phase 2 — Core Data & Admin UI
| Task | Mode |
|---|---|
| Write CPT registration and meta validation tests (red) | `[offline]` |
| Register `expedition` and `team` CPTs | `[offline]` |
| Write React component tests for Reconciliation Dashboard (red) | `[offline]` |
| Build Reconciliation Dashboard against mock data | `[offline]` |
| Implement Gravity Forms matching logic | `[offline]` |

### Phase 3 — Volunteer & Team Building
| Task | Mode |
|---|---|
| Write team code auto-generation tests (red) | `[offline]` |
| Build React Team Builder (drag-and-drop) | `[offline]` |
| Write Volunteer availability and confirmation state machine tests (red) | `[offline]` |
| Implement Volunteer signup and confirmation workflow | `[offline]` |
| Write push-back failure handling tests (red) | `[offline]` |
| Implement push-back failure persistence, notice, and retry | `[offline]` |

### Phase 4 — Frontend Portals
| Task | Mode |
|---|---|
| Write Explorer Portal component tests (red) | `[offline]` |
| Build Explorer Portal shortcode + SPA | `[offline]` |
| Write shell account merge flow tests (red) | `[offline]` |
| Implement Parent-Child parsing, selection UI, shell merge | `[offline]` |
| Write secure route upload validation tests (red) | `[offline]` |
| Setup secure route uploads | `[offline]` |
| Confirm SiteGround SMTP and implement email triggers | `[staging]` |

### Phase 5 — Production Sync & Launch
| Task | Mode |
|---|---|
| Write service account token refresh tests (red) | `[offline]` |
| Switch to `Live_Driver` | `[staging]` |
| Configure EMS service account authorisation flow | `[staging]` |
| Implement `.htaccess` protection for `ems-secure/` | `[staging]` |
| Load testing | `[staging]` |
| Final UI polish | `[live]` |

## 3. Intra-Phase Dependency Ordering

**Phase 1** (strictly sequential):
1. Docker environment
2. PHPUnit/Vitest setup
3. `OSM_API_Client` parsing tests (red)
4. `Mock_Driver` implementation (green)
5. Rate limiting tests (red) → token-bucket implementation (green) — **must precede any `Live_Driver` use**
6. Section Participant Pull prototype
7. `Auth_Provider` interface definition
8. `LoginWithGoogle_Auth_Provider` adapter
9. `Mock_Auth_Provider` implementation
10. `$_SESSION` bug fix in `OSM_Auth_Integration`

**Phase 2** (two parallel streams):
- **Stream A**: CPT tests (red) → CPT registration → meta validation
- **Stream B**: Reconciliation component tests (red) → Dashboard build → Gravity Forms logic

**Phase 3** (two parallel streams):
- **Stream A**: Team code tests → Team Builder UI
- **Stream B**: Volunteer tests → Volunteer workflow → Push-back failure tests → Push-back implementation

**Phase 4** (three parallel streams; Stream D blocks release):
- **Stream A**: Explorer Portal tests → Explorer Portal build
- **Stream B**: Shell merge tests → Parent-Child parsing + merge
- **Stream C**: Route upload tests → Route upload implementation
- **Stream D** *(blocks release)*: SMTP confirmation → email triggers (must follow all other Phase 4 tasks)

**Phase 5** (strictly sequential):
1. Token refresh tests (red)
2. `Live_Driver` switch
3. Service account auth flow
4. `.htaccess` protection
5. Load testing
6. Polish & training

## 4. Environment & Credential Checklist

Items marked **[human]** require a manual step from the operator. Halt and surface a blocking prompt rather than proceeding without them.

| Item | Required From | Source |
|---|---|---|
| Docker running (`docker-compose up`) | Phase 1 | Local |
| `EMS_TEST_MODE` constant in `wp-config.php` | Phase 1 | `docker-compose.yml` |
| Mock payloads in `tests/mocks/` | Phase 1 | `circularlizard/OSM-Tools` repo **[human]** |
| SiteGround staging SSH access | Phase 4 | **[human]** |
| SiteGround SMTP confirmed | Phase 4 | **[human]** |
| OSM service account OAuth tokens in WP Options | Phase 5 | One-time admin setup screen **[human]** |
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
