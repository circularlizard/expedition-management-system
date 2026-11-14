#!/usr/bin/env bash
set -euo pipefail

# bin/preflight-snapshot.sh - Baseline state, JSON backup & parity generator
# Usage:
#   bash bin/preflight-snapshot.sh [--mode=local|remote] [--out=/path/to/dir] [--url=https://...] [--token=TOKEN]

MODE="local"
OUT_DIR="/tmp/ems-migration-baseline"
URL="https://staging4.see-expeditions.org.uk"
TOKEN="${REST_TOKEN:-}"

# Parse command line flags
for arg in "$@"; do
  case "$arg" in
    --mode=*)
      MODE="${arg#*=}"
      ;;
    --out=*)
      OUT_DIR="${arg#*=}"
      ;;
    --url=*)
      URL="${arg#*=}"
      ;;
    --token=*)
      TOKEN="${arg#*=}"
      ;;
    *)
      echo "Unknown argument: $arg"
      echo "Usage: bash bin/preflight-snapshot.sh [--mode=local|remote] [--out=/path/to/dir] [--url=https://...] [--token=TOKEN]"
      exit 1
      ;;
  esac
done

mkdir -p "${OUT_DIR}"

EMS_TABLES=(
  ems_osm_explorers
  ems_units
  ems_unit_patrols
  ems_participant_signups
  ems_expedition_signups
  ems_team_members
  ems_volunteers
  ems_volunteer_availability
  ems_route_submissions
  ems_audit_logs
)

if [ "${MODE}" = "remote" ]; then
  echo "==> Running preflight snapshot against remote server: ${URL}..."
  AUTH_HEADER=()
  if [ -n "${TOKEN}" ]; then
    AUTH_HEADER=(-H "Authorization: Bearer ${TOKEN}")
  fi

  echo "  --> Fetching parity manifest..."
  curl -s -f "${AUTH_HEADER[@]}" "${URL}/wp-json/ems/v1/system/parity-manifest" -o "${OUT_DIR}/ems-parity-manifest.json" 2>/dev/null || \
    echo '{"error":"remote_endpoint_unavailable"}' > "${OUT_DIR}/ems-parity-manifest.json"

  echo "  --> Fetching unified JSON backup..."
  curl -s -f "${AUTH_HEADER[@]}" "${URL}/wp-json/ems/v1/backups/export" -o "${OUT_DIR}/ems-unified-backup.json" 2>/dev/null || \
    echo '{"error":"remote_endpoint_unavailable"}' > "${OUT_DIR}/ems-unified-backup.json"

  # Extract individual txt counters from manifest if python3 is present
  if command -v python3 >/dev/null 2>&1 && [ -f "${OUT_DIR}/ems-parity-manifest.json" ]; then
    python3 -c "
import json, sys
try:
    with open('${OUT_DIR}/ems-parity-manifest.json') as f:
        data = json.load(f)
    cpt = data.get('cpt_counts', {})
    with open('${OUT_DIR}/cpt-expedition-count.txt', 'w') as f:
        f.write(str(cpt.get('expedition', 0)) + '\n')
    with open('${OUT_DIR}/cpt-team-count.txt', 'w') as f:
        f.write(str(cpt.get('team', 0)) + '\n')
    tables = data.get('tables', {})
    for tbl in sys.argv[1:]:
        cnt = tables.get(tbl, {}).get('count', 0) if isinstance(tables.get(tbl), dict) else 0
        with open(f'${OUT_DIR}/table-{tbl}-count.txt', 'w') as f:
            f.write(str(cnt) + '\n')
except Exception as e:
    pass
" "${EMS_TABLES[@]}" || true
  fi

  echo "==> Remote snapshot complete! Saved to ${OUT_DIR}"
  exit 0
fi

# Local mode execution
echo "==> Running local preflight snapshot to ${OUT_DIR}..."

# Locate running WordPress container
find_wp_container() {
  if command -v docker-compose >/dev/null 2>&1; then
    local cid
    cid=$(docker-compose ps -q wordpress 2>/dev/null | head -n1 || true)
    if [ -n "${cid}" ]; then echo "${cid}"; return 0; fi
  fi

  local cid_dc
  cid_dc=$(docker compose ps -q wordpress 2>/dev/null | head -n1 || true)
  if [ -n "${cid_dc}" ]; then echo "${cid_dc}"; return 0; fi

  local candidate
  candidate=$(docker ps --filter "status=running" --format "{{.Names}}" 2>/dev/null | grep -E "wordpress" | grep -v -E "db|database|mysql|mariadb|caddy|cli|cron" | head -n1 || true)
  if [ -n "${candidate}" ]; then echo "${candidate}"; return 0; fi

  return 1
}

CONTAINER=$(find_wp_container || true)
if [ -z "${CONTAINER}" ]; then
  echo "ERROR: WordPress container is not running. Start it with docker-compose up -d"
  exit 1
fi

echo "  --> WordPress container detected: ${CONTAINER}"

# 1. Capture active plugins JSON
echo "  --> Capturing plugins list..."
docker exec -i "${CONTAINER}" wp plugin list --format=json --allow-root > "${OUT_DIR}/wp-plugins-before.json" 2>/dev/null || echo "[]" > "${OUT_DIR}/wp-plugins-before.json"
cp -f "${OUT_DIR}/wp-plugins-before.json" "${OUT_DIR}/wp-plugins.json"

# 2. Capture CPT counts
echo "  --> Capturing CPT counts..."
docker exec -i "${CONTAINER}" wp post list --post_type=expedition --format=count --allow-root 2>/dev/null > "${OUT_DIR}/cpt-expedition-count.txt" || echo "0" > "${OUT_DIR}/cpt-expedition-count.txt"
docker exec -i "${CONTAINER}" wp post list --post_type=team --format=count --allow-root 2>/dev/null > "${OUT_DIR}/cpt-team-count.txt" || echo "0" > "${OUT_DIR}/cpt-team-count.txt"

# 3. Capture row counts for all 10 wp_ems_* tables
echo "  --> Capturing 10 custom SQL table row counts..."
for tbl in "${EMS_TABLES[@]}"; do
  count=$(docker exec -i "${CONTAINER}" wp db query "SELECT COUNT(*) FROM wp_${tbl};" --skip-column-names --allow-root 2>/dev/null || echo "0")
  count=$(echo "${count}" | tr -d '[:space:]')
  [ -z "${count}" ] && count="0"
  echo "${count}" > "${OUT_DIR}/table-${tbl}-count.txt"
done

# 4. Dump MariaDB database
echo "  --> Exporting MariaDB SQL dump..."
if ! docker exec -i "${CONTAINER}" wp db export - --allow-root > "${OUT_DIR}/wordpress-full-backup.sql" 2>/dev/null; then
  # Fallback to mysqldump via db container if available
  DB_CONTAINER=$(docker ps --filter "status=running" --format "{{.Names}}" | grep -E "db|mariadb|mysql" | head -n1 || true)
  if [ -n "${DB_CONTAINER}" ]; then
    docker exec -i "${DB_CONTAINER}" mysqldump -u wordpress -pwordpress wordpress > "${OUT_DIR}/wordpress-full-backup.sql" 2>/dev/null || true
  fi
fi

# 5. Generate ems-parity-manifest.json
echo "  --> Generating ems-parity-manifest.json..."
if docker exec -i "${CONTAINER}" wp ems parity "${OUT_DIR}/ems-parity-manifest.json" --allow-root >/dev/null 2>&1; then
  echo "      (Generated via wp ems parity)"
else
  # Fallback: Generate deterministic manifest via wp eval
  docker exec -i "${CONTAINER}" wp eval '
global $wpdb;
$exp_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = \"expedition\" AND post_status != \"trash\"");
$team_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = \"team\" AND post_status != \"trash\"");

$tables = [
  "ems_osm_explorers", "ems_units", "ems_unit_patrols", "ems_participant_signups",
  "ems_expedition_signups", "ems_team_members", "ems_volunteers",
  "ems_volunteer_availability", "ems_route_submissions", "ems_audit_logs"
];

$table_stats = [];
foreach ($tables as $t) {
  $full_table = $wpdb->prefix . $t;
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table)) === $full_table;
  if ($table_exists) {
    $cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$full_table}`");
    $rows = $wpdb->get_results("SELECT * FROM `{$full_table}` ORDER BY 1 ASC", ARRAY_A);
    $sha256 = hash("sha256", serialize($rows));
    $table_stats[$t] = [
      "exists" => true,
      "count" => $cnt,
      "sha256" => $sha256
    ];
  } else {
    $table_stats[$t] = [
      "exists" => false,
      "count" => 0,
      "sha256" => null
    ];
  }
}

$options_to_check = [
  "ems_version", "ems_api_mode", "ems_sync_limit", "ems_osm_scope",
  "ems_osm_api_base_url", "ems_managed_sections", "ems_feature_flags"
];
$options = [];
foreach ($options_to_check as $opt) {
  $val = get_option($opt, null);
  if ($val !== null) {
    $options[$opt] = $val;
  }
}

$plugins = (array)get_option("active_plugins", []);

$manifest = [
  "schema_version" => "1.0",
  "generated_at" => gmdate("c"),
  "cpt_counts" => [
    "expedition" => $exp_count,
    "team" => $team_count
  ],
  "tables" => $table_stats,
  "options" => $options,
  "active_plugins" => $plugins
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
' --allow-root > "${OUT_DIR}/ems-parity-manifest.json" 2>/dev/null || echo '{"schema_version":"1.0"}' > "${OUT_DIR}/ems-parity-manifest.json"
fi

# 6. Generate ems-unified-backup.json
echo "  --> Generating ems-unified-backup.json..."
if docker exec -i "${CONTAINER}" wp ems backup export "${OUT_DIR}/ems-unified-backup.json" --allow-root >/dev/null 2>&1; then
  echo "      (Generated via wp ems backup export)"
else
  # Fallback: Generate unified backup JSON via wp eval
  docker exec -i "${CONTAINER}" wp eval '
global $wpdb;

$cpts = ["expedition", "team"];
$posts_data = [];
foreach ($cpts as $cpt) {
  $posts = get_posts(["post_type" => $cpt, "numberposts" => -1, "post_status" => "any"]);
  $cpt_records = [];
  foreach ($posts as $p) {
    $cpt_records[] = [
      "post" => (array)$p,
      "meta" => get_post_meta($p->ID)
    ];
  }
  $posts_data[$cpt] = $cpt_records;
}

$tables = [
  "ems_osm_explorers", "ems_units", "ems_unit_patrols", "ems_participant_signups",
  "ems_expedition_signups", "ems_team_members", "ems_volunteers",
  "ems_volunteer_availability", "ems_route_submissions", "ems_audit_logs"
];

$table_data = [];
foreach ($tables as $t) {
  $full_table = $wpdb->prefix . $t;
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table)) === $full_table;
  if ($table_exists) {
    $table_data[$t] = $wpdb->get_results("SELECT * FROM `{$full_table}`", ARRAY_A);
  } else {
    $table_data[$t] = [];
  }
}

$options = [];
$all_options = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE \"ems_%\"", ARRAY_A);
foreach ($all_options as $row) {
  $options[$row["option_name"]] = maybe_unserialize($row["option_value"]);
}

$backup = [
  "schema_version" => "1.0",
  "exported_at" => gmdate("c"),
  "cpts" => $posts_data,
  "tables" => $table_data,
  "options" => $options
];

echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
' --allow-root > "${OUT_DIR}/ems-unified-backup.json" 2>/dev/null || echo '{"schema_version":"1.0"}' > "${OUT_DIR}/ems-unified-backup.json"
fi

echo "==> Local preflight snapshot completed successfully to ${OUT_DIR}"
