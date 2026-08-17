#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${ROOT}/deployment.zip"
cd "$ROOT"
rm -f "$OUT"
zip -r "$OUT" \
  pms_core \
  public_html \
  db_migrations \
  tests \
  scripts \
  .env.example \
  DEPLOY.md \
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
  -x "*dump_db.php" \
  -x "*query.php" \
  -x "*fix_db.php" \
  -x "*patch_constraints.php" \
  -x "*add_signature_col.php" \
  -x "*test_api.php" \
  -x "*test_finance.php" \
  -x "*test_finance.html" \
  -x "*.bak" \
  -x "*uploads*" \
  -x "*pms_core/libs/tutorial*" \
  -x "*pms_core/libs/doc*" \
  -x "*pms_core/libs/FAQ.htm" \
  -x "*pms_core/libs/changelog.htm" \
  -x "*pms_core/libs/install.txt" \
  >/dev/null
echo "Wrote $OUT ($(du -h "$OUT" | awk '{print $1}'))"
unzip -l "$OUT" | tail -n 8
