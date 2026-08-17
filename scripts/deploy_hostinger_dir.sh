#!/usr/bin/env bash
# Upload one local directory to a sibling path on Hostinger via FTP/FTPS/SFTP.
# Does not delete remote files. Does not upload .env or uploads/.
set -euo pipefail

if [[ $# -ne 2 ]]; then
  echo "Usage: $0 <local-dir> <remote-dir>" >&2
  exit 1
fi

LOCAL_DIR="$1"
REMOTE_DIR="${2#/}"

if [[ ! -d "$LOCAL_DIR" ]]; then
  echo "Local directory not found: $LOCAL_DIR" >&2
  exit 1
fi

: "${FTP_SERVER:?FTP_SERVER is required}"
: "${FTP_USERNAME:?FTP_USERNAME is required}"
: "${FTP_PASSWORD:?FTP_PASSWORD is required}"

PROTOCOL="$(echo "${FTP_PROTOCOL:-ftp}" | tr '[:upper:]' '[:lower:]')"
PORT="${FTP_PORT:-}"

case "$PROTOCOL" in
  ftp)
    PORT="${PORT:-21}"
    ;;
  ftps)
    PORT="${PORT:-21}"
    ;;
  sftp)
    PORT="${PORT:-65002}"
    ;;
  *)
    echo "FTP_PROTOCOL must be ftp, ftps, or sftp (got: $PROTOCOL)" >&2
    exit 1
    ;;
esac

export LFTP_PASSWORD="$FTP_PASSWORD"

LFTP_CMDS=""
if [[ "$PROTOCOL" == "sftp" ]]; then
  LFTP_CMDS+="set sftp:auto-confirm yes; "
elif [[ "$PROTOCOL" == "ftps" ]]; then
  LFTP_CMDS+="set ftp:ssl-force true; set ftp:ssl-protect-data true; set ssl:verify-certificate yes; "
fi

lftp -u "$FTP_USERNAME" --env-password -e "
set cmd:fail-exit yes;
set net:max-retries 3;
set net:reconnect-interval-base 5;
${LFTP_CMDS}
open ${PROTOCOL}://${FTP_SERVER}:${PORT};
mkdir -p ${REMOTE_DIR};
cd ${REMOTE_DIR};
lcd ${LOCAL_DIR};
mirror -R --verbose --exclude-glob .env --exclude-glob .env.* --exclude-glob .DS_Store --exclude-glob '*.log' --exclude uploads/;
bye
"
echo "Uploaded ${LOCAL_DIR}/ -> /${REMOTE_DIR}/"
