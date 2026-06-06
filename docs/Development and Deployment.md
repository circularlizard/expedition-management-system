# Development and Deployment Strategy: EMS

This document outlines the environment, testing, and incremental rollout plan for the Expedition Management System.

## 1. Development Environment
### 1.1 Local Development (Docker)
To ensure parity with the test server, we will use a Docker-based local environment:
- **Image**: `wordpress:php8.2-apache` (or similar SiteGround-aligned image).
- **Database**: `mariadb:latest`.
- **Tools**: WP-CLI, Composer, Node/NPM.

### 1.2 Testing Infrastructure
In alignment with **ADR 007 (TDD Mandate)**:
- **PHP Testing**: `phpunit/phpunit` and `weaseur/brain-monkey` installed via Composer.
- **JS Testing**: `vitest` and `@testing-library/react` installed via NPM.
- **E2E Testing**: `playwright` for cross-browser validation on the staging environment.

### 1.3 Test Server
- **Environment**: A staging/test subdomain on SiteGround.
- **CI/CD**: TBD (Manual deployment or basic GitHub Actions to push via SSH/SFTP).

## 2. OSM Integration Strategy
### 2.1 Authentication (OIDC)
- **Base Plugin**: [login-with-google](https://github.com/circularlizard/login-with-google) (configured for OSM OIDC).
- **Extension**: We will extend or hook into this plugin to capture the OSM Access Token and store it in the user's session or transient for subsequent API calls.

### 2.2 Rate Limiting & Performance
OSM has strict rate limits. Our integration must include:
- **Throttling**: A central `OSM_API_Client` class that implements a "Token Bucket" or simple delay logic to ensure we never exceed the allowed requests per minute.
- **Caching**: Aggressive use of WordPress Transients to cache OSM data (e.g., Section lists, Event details) for 1–12 hours.
- **Batching**: Where the API allows, fetch data in batches rather than individual requests per user.

### 2.3 Mock Data Layer (Test Mode)
- **Implementation**: The `OSM_API_Client` will use a "Driver" pattern.
- **Drivers**:
    - `Live_Driver`: Makes real HTTP requests to OSM.
    - `Mock_Driver`: Returns static JSON payloads (stored in `tests/mocks/`) for all data requests.
- **Switching**: Controlled via a WP Option or `EMS_TEST_MODE` constant in `wp-config.php`.

## 3. Incremental Implementation Plan

### Phase 1: Infrastructure & Test Setup (Current)
- **Goal**: Establish the "Test-First" environment and verify OSM API connectivity.
- **Tasks**:
    - Configure local Docker environment.
    - Setup PHPUnit and Vitest test runners.
    - **TDD Task**: Write failing tests for the `OSM_API_Client` data parsing.
    - Implement "Mock Driver" to satisfy parsing tests using payloads from [OSM-Tools](https://github.com/circularlizard/OSM-Tools).
    - Prototype the "Section Participant Pull" to verify parsing logic via tests.

### Phase 2: Core Data & Admin UI
- **Goal**: Implement CPTs and basic management via TDD.
- **Tasks**:
    - **TDD Task**: Write tests for CPT registration and meta field validation.
    - Register `expedition` and `team` CPTs.
    - **TDD Task**: Define React component tests for the Reconciliation view.
    - Build the React-based "Reconciliation Dashboard" using mock data.
    - Implement Gravity Forms matching logic, verified by unit tests.

### Phase 3: Volunteer & Team Building
- **Goal**: Enable staffing and participant grouping.
- **Tasks**:
    - Build the React "Team Builder" (Drag-and-drop).
    - Implement Volunteer signup and "Confirmation" workflow.

### Phase 4: Frontend Portals
- **Goal**: Launch Explorer and Parent views.
- **Tasks**:
    - Create the React "Explorer Portal" shortcode.
    - Implement Parent-Child relationship parsing and selection UI.
    - Setup secure Route Planning uploads.

### Phase 5: Production Sync & Launch
- **Goal**: Full integration and live testing.
- **Tasks**:
    - Switch to `Live_Driver` for OSM.
    - Perform load testing on the SiteGround test server.
    - Final UI polish and user training.
