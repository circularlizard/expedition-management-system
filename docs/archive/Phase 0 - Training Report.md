# Phase 0: Local Dev Setup & Training Completion Report

## 1. Goal

Establish the foundation before any OSM integration work begins:

1. A working local Docker development environment with Tutor LMS installed.
2. A repeatable build, package, and deploy workflow (CI + zip artefact).
3. The first shippable EMS feature: a **Training Completion Report** admin page that queries Tutor LMS and shows which courses each student has completed, with a CSV export.

---

## 2. Local Development Environment

### 2.1 Docker Compose

`docker-compose.yml` is extended/pinned:

- **WordPress**: `wordpress:php8.2-apache`
- **Database**: `mariadb:10.11`
- **WP-CLI**: available via `docker-compose run --rm wpcli wp <command>`

The EMS plugin directory is bind-mounted into the container at
`/var/www/html/wp-content/plugins/ems-plugin`.

### 2.2 Tutor LMS (Free Tier — Local)

Tutor LMS Free is used locally for development and unit testing. The production SiteGround server runs Tutor LMS Pro. Because the REST API requires Pro, **all server-side data access uses Tutor LMS PHP functions directly** — no REST calls are made. This keeps the local and production code paths identical at the function-call level.

Install and activate via WP-CLI after first `docker-compose up`:

```bash
wp plugin install tutor --activate
```

### 2.3 Test Data Seeding

A WP-CLI seed script (`bin/seed-test-data.sh`) creates repeatable test data:

- 3 published Tutor LMS courses.
- 5 student WP users, each enrolled in a subset of those courses.
- At least one student with all courses completed, one with mixed status, one with none.

This seed state underpins manual QA and confirms the report renders correctly against known data.

### 2.4 `EMS_TEST_MODE`

`wp-config.php` in the Docker environment defines:

```php
define( 'EMS_TEST_MODE', true );
```

This constant gates any test-mode overrides added in later phases.

---

## 3. Build & Deployment

### 3.1 GitHub Actions CI

Workflow file: `.github/workflows/ci.yml`

Triggers on every push to `main` and all pull requests. Steps:

1. PHP lint — `find src -name "*.php" -exec php -l {} \;`
2. Composer install
3. PHPUnit test suite — `vendor/bin/phpunit`

No JS build step is required for Phase 0.

### 3.2 Plugin Packaging

Script: `bin/package.sh`

1. Runs `composer install --no-dev --optimize-autoloader` into a temp copy.
2. Strips non-distributable paths: `tests/`, `docker-compose.yml`, `.github/`, `bin/`, `.gitignore`, `phpunit.xml`.
3. Archives output as `ems-plugin-{VERSION}.zip` (version read from plugin header in `ems-plugin.php`).

```bash
bash bin/package.sh
# → dist/ems-plugin-0.1.0.zip
```

### 3.3 Deployment to SiteGround Staging

Manual upload via **WP Admin → Plugins → Add New → Upload Plugin**.

The `dist/ems-plugin-{VERSION}.zip` produced by `bin/package.sh` is the upload artefact. On initial install, activate via WP Admin. For subsequent updates, deactivate → delete → re-upload, or use a plugin like **WP Rollback** / manual file replace via File Manager if available.

> **Note**: SSH/SFTP-based automated deploy can be added in a later phase if SiteGround SSH access is confirmed.

---

## 4. Feature: Training Completion Report

### 4.1 Overview

A new WP admin page added to the **EMS** top-level admin menu:

- **Menu**: EMS → Training Report
- **Access**: `manage_options` capability (site admins only).

The page displays a matrix of all Tutor LMS students against all published courses, with completion status for each combination.

### 4.2 On-Screen Report

| Column | Description |
|---|---|
| **Student Name** | WP display name |
| **Email** | WP user email |
| **[Course N]** | One column per published Tutor LMS course |

Cell values:

| Value | Meaning |
|---|---|
| ✓ Complete | `is_completed_course()` returns true |
| ⏳ In Progress | Enrolled but not yet completed |
| — | Not enrolled in this course |

Pagination is applied if the student count exceeds 50.

### 4.3 CSV Export

An **Export CSV** button at the top of the page triggers a PHP-handled download.

- **Filename**: `ems-training-report-{YYYY-MM-DD}.csv`
- **Header row**: `Student Name, Email, [Course 1 title], [Course 2 title], ...`
- **Cell values**: `Complete`, `In Progress`, `Not Enrolled`
- No external library required — uses native PHP `fputcsv`.

### 4.4 Technical Implementation

#### Data access (Tutor LMS PHP, free-tier compatible)

| Need | Function |
|---|---|
| All published courses | `get_posts(['post_type' => 'courses', 'post_status' => 'publish', 'posts_per_page' => -1])` |
| Students enrolled in a course | `tutor_utils()->get_students_data($course_id)` |
| Completion check | `tutor_utils()->is_completed_course($course_id, $user_id)` |

#### New classes

| File | Class |
|---|---|
| `src/Integrations/TutorLMS_Client.php` | `EMS\Integrations\TutorLMS_Client` |
| `src/Admin/Training_Report_Page.php` | `EMS\Admin\Training_Report_Page` |

`TutorLMS_Client` wraps all Tutor LMS and WP calls behind thin methods. Tests inject a mock implementation. `Training_Report_Page` depends only on `TutorLMS_Client`, making the data-assembly and CSV logic fully unit-testable without a running WP install.

#### Registration

`src/Plugin.php` registers the admin page on `admin_menu`:

```php
add_action( 'admin_menu', [ $this->training_report_page, 'register' ] );
```

### 4.5 TDD Tasks (Phase 0)

All tests live under `tests/Unit/` mirroring `src/`.

1. **Red** — Write failing tests for `TutorLMS_Client`:
   - `get_all_courses()` returns an array of course objects.
   - `get_enrolled_students($course_id)` returns user objects for a given course.
   - `is_completed($course_id, $user_id)` returns bool.
   - `get_enrollment_status($course_id, $user_id)` returns `'complete'`, `'in_progress'`, or `'not_enrolled'`.

2. **Green** — Implement `TutorLMS_Client` using Brain Monkey to mock `get_posts()` and `tutor_utils()`.

3. **Red** — Write failing tests for `Training_Report_Page`:
   - `build_report_data()` returns correct matrix structure given a known `TutorLMS_Client` mock.
   - `render_csv_headers()` sends correct `Content-Type` and `Content-Disposition` headers.
   - `generate_csv_rows()` produces correctly formatted rows.

4. **Green** — Implement `Training_Report_Page`.

---

## 5. Phase 0 Acceptance Criteria

- [ ] `docker-compose up` starts WP + MariaDB cleanly; site reachable at `http://localhost:8080`.
- [ ] Tutor LMS free installed and active; seed script runs without errors.
- [ ] EMS plugin active; WP Admin shows **EMS → Training Report** menu item.
- [ ] Report table renders with correct completion status for seeded test data.
- [ ] Export CSV download produces a well-formed file matching the on-screen table.
- [ ] `vendor/bin/phpunit` runs and all Phase 0 tests pass.
- [ ] GitHub Actions CI passes on push (PHP lint + PHPUnit).
- [ ] `bin/package.sh` produces `dist/ems-plugin-{VERSION}.zip` that installs cleanly on a fresh WP install via WP Admin upload.
