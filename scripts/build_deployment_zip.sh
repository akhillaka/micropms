#!/usr/bin/env bash
# Build a Hostinger drop-in zip: public_html + pms_core + db_migrations as siblings.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${ROOT}/deployment.zip"
cd "$ROOT"

mkdir -p public_html/uploads pms_core/uploads
: >> public_html/uploads/.gitkeep
: >> pms_core/uploads/.gitkeep

rm -f "$OUT"
zip -r "$OUT" \
  pms_core \
  public_html \
  db_migrations \
  tests \
  scripts \
  .env.example \
  DEPLOY.md \
  EXTRACT.txt \
  -x "*.git*" \
  -x "*.env" \
  -x "*node_modules*" \
  -x "*.cursor*" \
  -x "*agent-transcripts*" \
  -x "*.log" \
  -x "*.DS_Store" \
  -x "*coverage*" \
  -x "*deployment.zip" \
  -x "*cookies.txt" \
  -x "*csrf.txt" \
  -x "*add_notifications.php" \
  -x "*dump_db.php" \
  -x "*query.php" \
  -x "*fix_db.php" \
  -x "*patch_constraints.php" \
  -x "*add_signature_col.php" \
  -x "*test_api.php" \
  -x "*test_finance.php" \
  -x "*test_finance.html" \
  -x "*.bak" \
  -x "public_html/uploads/*" \
  -x "pms_core/uploads/*" \
  -x "*pms_core/libs/tutorial*" \
  -x "*pms_core/libs/doc*" \
  -x "*pms_core/libs/FAQ.htm" \
  -x "*pms_core/libs/changelog.htm" \
  -x "*pms_core/libs/install.txt" \
  >/dev/null

# Empty upload dirs (keep folders; do not pack guest/ID files)
zip "$OUT" \
  public_html/uploads/.gitkeep \
  public_html/uploads/.htaccess \
  pms_core/uploads/.gitkeep \
  pms_core/uploads/.htaccess \
  >/dev/null

NAMES="$(unzip -Z -1 "$OUT")"
missing=0
require() {
  local path="$1"
  if ! grep -Fxq "$path" <<<"$NAMES"; then
    echo "MISSING in zip: $path" >&2
    missing=1
  fi
}

require "public_html/.htaccess"
require "public_html/index.php"
require "public_html/router.php"
require "public_html/cron_scheduler.php"
require "public_html/admin/login.php"
require "public_html/admin/index.php"
require "public_html/admin/run_migration.php"
require "public_html/landing/index.php"
require "public_html/saas-admin/index.php"
require "public_html/assistant/index.html"
require "public_html/uploads/.htaccess"
require "pms_core/.htaccess"
require "pms_core/ModuleHost.php"
require "pms_core/Database.php"
require "pms_core/config.php"
require "pms_core/api_routes.php"
require "pms_core/cron_worker.php"
require "pms_core/libs/fpdf.php"
require "pms_core/uploads/.htaccess"
require "db_migrations/028_saas_leads.sql"
require "db_migrations/029_payment_gateways_settings_sync.sql"
require "db_migrations/030_guest_id_fields.sql"
require "db_migrations/031_schema_alignment.sql"
require "db_migrations/032_service_requests_pos_channel.sql"
require "db_migrations/033_room_dnd.sql"
require "db_migrations/034_staff_pwa_push.sql"
require "db_migrations/035_staff_roles_enum.sql"
require "pms_core/services/StayPolicy.php"
require "pms_core/services/TelegramCalendar.php"
require "public_html/sw.js"
require "public_html/js/staff-alert-sound.js"
require "public_html/sounds/staff-alert.wav"
require "public_html/manifest.webmanifest"
require "public_html/icons/icon-192.png"
require "public_html/icons/icon-512.png"
require "public_html/js/pwa.js"
require ".env.example"
require "DEPLOY.md"
require "EXTRACT.txt"

if grep -q 'isPreviewHost' <(unzip -p "$OUT" pms_core/ModuleHost.php); then
  :
else
  echo "MISSING preview-host fix in pms_core/ModuleHost.php" >&2
  missing=1
fi

if [[ "$missing" -ne 0 ]]; then
  echo "deployment.zip is incomplete" >&2
  exit 1
fi

echo "Wrote $OUT ($(du -h "$OUT" | awk '{print $1}'))"
echo "Files: $(unzip -Z -1 "$OUT" | wc -l | tr -d ' ')"
unzip -l "$OUT" | tail -n 6
