#!/usr/bin/env bash
# Wiederherstellung einer Sicherung von scripts/backup.sh in den laufenden Docker-Stapel.
#
#   scripts/restore.sh <Ordner>      (im Verzeichnis mit docker-compose.yml; der Stapel läuft: docker compose up -d)
#
# Ablauf: Prüfsummen (SHA256SUMS) -> Wartungsmodus an -> worker/scheduler/reverb anhalten -> alle Tabellen und Views
# löschen (der Dump löscht nicht, was eine neuere Version angelegt hat) -> Dump einspielen -> app anhalten ->
# Storage-Volume leeren und aus storage.tgz füllen (mit storage/app.key) -> docker compose up -d (app startet mit dem
# gesicherten Schlüssel, AUTORUN migriert, falls das Image neuer ist als die Sicherung) -> Wartungsmodus aus.
# Alles seit der Sicherung Geschriebene geht verloren. Updater-Volumes (update-state, update-backups) bleiben.
# Achtung: Die Tabellen werden vor dem Einspielen gelöscht, ein Abbruch danach hinterlässt eine leere oder halbe
# Datenbank. Vorher mit scripts/backup.sh eine aktuelle Sicherung ziehen. Bricht das Skript ab, meldet es den Zustand
# (Wartungsmodus an, Dienste gestoppt); dann den Lauf wiederholen oder per docker compose exec app php artisan up öffnen.
#
# Geprüft (Wegwerf-Stapel -p ignis_restore): ignis:install per CLI, API-Schlüssel (verschlüsselte Einstellung)
# und eine Datei im Storage angelegt, backup.sh, docker compose down -v, up -d (neuer Schlüssel, leere Datenbank),
# restore.sh; danach derselbe API-Schlüssel lesbar, die Datei da, /healthz ok.
set -euo pipefail
export MSYS_NO_PATHCONV=1

die() { echo "restore: $*" >&2; exit 1; }

dir="${1:-}"
[ -n "$dir" ] && [ -f "$dir/SHA256SUMS" ] || die "Aufruf: scripts/restore.sh <Ordner mit SHA256SUMS>"

echo "==> Prüfsummen"
(cd "$dir" && sha256sum -c SHA256SUMS) || die "Prüfsummen stimmen nicht"

project="$(docker compose config --format json | sed -n 's/^  "name": "\(.*\)",\{0,1\}$/\1/p' | head -n 1)"
[ -n "$project" ] || die "Projektname nicht ermittelbar (Verzeichnis mit docker-compose.yml?)"
volume="${project}_storage"
docker volume inspect "$volume" >/dev/null 2>&1 || die "Volume $volume fehlt: zuerst docker compose up -d"
[ -n "$(docker compose ps -q db)" ] || die "db läuft nicht: zuerst docker compose up -d"

echo "==> Wartungsmodus an, Hintergrunddienste anhalten"
trap 'echo "restore: abgebrochen. Wartungsmodus an, Dienste gestoppt - restore.sh wiederholen oder manuell: docker compose up -d && docker compose exec app php artisan up" >&2' ERR
docker compose exec -T app php artisan down --retry=60 || echo "restore: app antwortet nicht, weiter ohne Wartungsmodus" >&2
others="$(docker compose ps --services | grep -vx -e db -e app -e updater || true)"
if [ -n "$others" ]; then
    # shellcheck disable=SC2086 # Dienstnamen ohne Leerzeichen
    docker compose stop $others
fi

echo "==> Datenbank leeren und $dir/db.sql.gz einspielen"
# Das Skript (stdin) läuft in der Shell des db-Containers, die Zugangsdaten kommen aus dessen Umgebung.
docker compose exec -T db sh -s <<'SH'
set -e
export MYSQL_PWD="$MARIADB_PASSWORD"
drops="$(mariadb -u"$MARIADB_USER" -N -B -e "SELECT CONCAT(IF(TABLE_TYPE = 'VIEW', 'DROP VIEW IF EXISTS ', 'DROP TABLE IF EXISTS '), CHAR(96), TABLE_NAME, CHAR(96), ';') FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()" "$MARIADB_DATABASE")"
printf 'SET FOREIGN_KEY_CHECKS=0;\n%s\n' "$drops" | mariadb -u"$MARIADB_USER" "$MARIADB_DATABASE"
SH
# shellcheck disable=SC2016 # die Variablen wertet die Shell im db-Container aus
gzip -dc "$dir/db.sql.gz" | docker compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -u"$MARIADB_USER" --default-character-set=utf8mb4 "$MARIADB_DATABASE"'

echo "==> Volume $volume aus $dir/storage.tgz"
docker compose stop app
docker run --rm -i -v "$volume":/s alpine:3.22@sha256:2e0b81d60597f449baa56dc25d355367869d2dca4cd57e866f1e2b52af3eb55f sh -c 'find /s -mindepth 1 -delete && tar xzf - -C /s' <"$dir/storage.tgz"

echo "==> Stapel starten, Wartungsmodus aus"
docker compose up -d
for _ in $(seq 1 90); do
    if docker compose exec -T app php artisan up 2>/dev/null; then
        echo "OK wiederhergestellt aus $dir"
        exit 0
    fi
    sleep 2
done
die "app antwortet nicht; später: docker compose exec app php artisan up"
