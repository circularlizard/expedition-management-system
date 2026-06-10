#!/usr/bin/env bash
set -e

echo "==> Seeding EMS test data..."

docker-compose run --rm wpcli eval-file \
  wp-content/plugins/ems-plugin/bin/seed-test-data.php

echo "==> Done. Visit http://localhost:8080/wp-admin to verify."
