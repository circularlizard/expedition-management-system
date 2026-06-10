# Expedition Management System (EMS) — Developer Guide

WordPress plugin for managing DofE expeditions. See `docs/` for full specifications.

---

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (includes Compose)
- [Composer](https://getcomposer.org/) ≥ 2
- PHP ≥ 8.2 (for running tests locally outside Docker)

---

## First-Time Setup

### 1. Install PHP dependencies

```bash
composer install
```

### 2. Start the containers

```bash
docker-compose up -d
```

Wait ~15 seconds for WordPress and MariaDB to initialise. The WordPress container writes its files into the `wordpress_data` volume on first boot.

Check it's ready:
```bash
docker-compose run --rm wpcli core is-installed
# Should print nothing and exit 0. If it exits 1, wait a few more seconds and retry.
```

### 3. Install WordPress

```bash
docker-compose run --rm wpcli core install \
  --url="http://localhost:8080" \
  --title="EMS Dev" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=admin@example.com
```

### 4. Install and activate Tutor LMS

```bash
docker-compose run --rm wpcli plugin install tutor --activate
```

### 5. Activate the EMS plugin

```bash
docker-compose run --rm wpcli plugin activate ems-plugin
```

### 6. Seed test data

```bash
bash bin/seed-test-data.sh
```

> **Note**: `bin/seed-test-data.sh` is created as part of Phase 0. Until it exists, you can create courses and users manually via the WP Admin at [http://localhost:8080/wp-admin](http://localhost:8080/wp-admin) (admin / admin).

---

## Day-to-Day Usage

| Task | Command |
|---|---|
| Start containers | `docker-compose up -d` |
| Stop containers | `docker-compose stop` |
| WP-CLI command | `docker-compose run --rm wpcli <wp command>` |
| View WP logs | `docker-compose logs -f wordpress` |
| Open WP Admin | [http://localhost:8080/wp-admin](http://localhost:8080/wp-admin) — admin / admin |

---

## Running Tests

Tests run locally against your installed Composer dependencies — no Docker needed.

```bash
vendor/bin/phpunit
```

The test suite uses [Brain Monkey](https://brain-wp.github.io/BrainMonkey/) to mock WordPress and Tutor LMS functions, so a live WP install is not required.

---

## Resetting to a Clean State

To wipe the database and WordPress files and start fresh:

```bash
docker-compose down -v
docker-compose up -d
# Then repeat steps 3–6 above
```

> `down -v` removes all named volumes (`db_data`, `wordpress_data`). The EMS plugin source files in your working directory are unaffected.

---

## Project Structure

```
ems-plugin.php          # Plugin entry point
src/                    # PHP source (PSR-4, EMS\ namespace)
  Admin/                # WP admin pages and controllers
  Integrations/         # Third-party integrations (Tutor LMS, OSM)
  Core/                 # CPT registration, table installer
  Auth/                 # Auth provider interface and adapters
  Data/                 # Repository classes
tests/
  Unit/                 # PHPUnit unit tests (mirrors src/)
  mocks/                # Static JSON payloads for mock drivers
  bootstrap.php         # PHPUnit bootstrap
  EMSTestCase.php       # Base test case (Brain Monkey setup/teardown)
docs/                   # Specifications and architecture docs
bin/                    # Build and seed scripts
```

## Key Docs

| Document | Purpose |
|---|---|
| `docs/Phase 0 - Training Report.md` | Current phase spec and acceptance criteria |
| `docs/Expedition Management System.md` | Full PRD |
| `docs/Technical Architecture.md` | ADRs and component design |
| `docs/Development and Deployment.md` | All phases, TDD tasks, source directory map |
