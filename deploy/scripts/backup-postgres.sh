#!/usr/bin/env bash
set -Eeuo pipefail

: "${PGHOST:=127.0.0.1}"
: "${PGPORT:=5432}"
: "${PGDATABASE:?PGDATABASE wajib diisi}"
: "${PGUSER:?PGUSER wajib diisi}"
: "${BACKUP_DIR:=/var/backups/antrian-ku}"
umask 077
mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
file="$BACKUP_DIR/${PGDATABASE}_${stamp}.dump"
pg_dump --format=custom --no-owner --no-privileges --file="$file"
sha256sum "$file" > "$file.sha256"
find "$BACKUP_DIR" -type f -name '*.dump' -mtime +14 -delete
find "$BACKUP_DIR" -type f -name '*.sha256' -mtime +14 -delete
echo "Backup dibuat: $file"
