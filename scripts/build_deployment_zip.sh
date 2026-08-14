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
  >/dev/null
echo "Wrote $OUT"
unzip -l "$OUT" | tail -n 5
