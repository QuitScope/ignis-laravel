#!/usr/bin/env bash
# Datensicherung des Docker-Stapels: Datenbank (mariadb-dump als App-Benutzer) und Storage-Volume (enthält
# storage/app.key, ohne den verschlüsselte Einstellungen unlesbar sind) in einen Zeitstempel-Ordner, mit Prüfsummen.
#
#   scripts/backup.sh [Zielordner] [--keep N]      (im Verzeichnis mit docker-compose.yml aufrufen)
#
# Zielordner Standard ./backups; darin <UTC-Zeit>/{db.sql.gz,storage.tgz,SHA256SUMS}. --keep N (Standard 7) behält
# die N neuesten Sicherungen im Zielordner. Zurückspielen: scripts/restore.sh <Ordner>. Die Sicherung enthält die
# ganze Datenbank und den Schlüssel: vertraulich, gehört nicht auf denselben Datenträger allein.
set -euo pipefail
# Git Bash (Windows): Pfade wie /s in Docker-Argumenten nicht umschreiben.
export MSYS_NO_PATHCONV=1

die() { echo "backup: $*" >&2; exit 1; }

dest=backups
keep=7
while [ $# -gt 0 ]; do
    case "$1" in
        --keep) keep="${2:-}"; shift 2 || die "--keep braucht eine Zahl" ;;
        --keep=*) keep="${1#*=}"; shift ;;
        -h | --help) sed -n '2,9p' "$0"; exit 0 ;;
        -*) die "unbekannte Option $1" ;;
        *) dest="$1"; shift ;;
    esac
done
[[ "$keep" =~ ^[1-9][0-9]*$ ]] || die "--keep: ganze Zahl >= 1 erwartet, nicht '$keep'"

# Projektname wie Compose ihn bildet (name:, COMPOSE_PROJECT_NAME, -p); das Volume heißt <projekt>_storage.
project="$(docker compose config --format json | sed -n 's/^  "name": "\(.*\)",\{0,1\}$/\1/p' | head -n 1)"
[ -n "$project" ] || die "Projektname nicht ermittelbar (Verzeichnis mit docker-compose.yml?)"
volume="${project}_storage"
docker volume inspect "$volume" >/dev/null 2>&1 || die "Volume $volume fehlt"

umask 077
dir="$dest/$(date -u +%Y%m%d-%H%M%S)"
[ ! -e "$dir" ] || die "$dir existiert schon"
mkdir -p "$dir"
trap '[ "$?" -eq 0 ] || rm -rf "$dir"' EXIT

echo "==> Datenbank ($project) -> $dir/db.sql.gz"
# shellcheck disable=SC2016 # die Variablen wertet die Shell im db-Container aus
docker compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb-dump --single-transaction --routines --triggers --default-character-set=utf8mb4 -u"$MARIADB_USER" "$MARIADB_DATABASE"' |
    gzip >"$dir/db.sql.gz"
gzip -dc "$dir/db.sql.gz" | tail -n 1 | grep -q '^-- Dump completed' || die "Dump unvollständig"

echo "==> Volume $volume -> $dir/storage.tgz"
docker run --rm -v "$volume":/s:ro alpine:3.22@sha256:2e0b81d60597f449baa56dc25d355367869d2dca4cd57e866f1e2b52af3eb55f tar czf - -C /s . >"$dir/storage.tgz"
gzip -t "$dir/storage.tgz" || die "storage.tgz beschädigt"

(cd "$dir" && sha256sum db.sql.gz storage.tgz >SHA256SUMS)

# Aufbewahrung: nur Ordner mit dem eigenen Namensmuster, die ältesten zuerst.
old="$(find "$dest" -mindepth 1 -maxdepth 1 -type d -name '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]' | sort | head -n "-$keep")"
if [ -n "$old" ]; then
    printf '%s\n' "$old" | while IFS= read -r d; do echo "==> entferne alte Sicherung $d"; rm -rf "$d"; done
fi

echo "OK $dir"
