#!/bin/sh
# Runs once on a fresh db volume (docker-entrypoint-initdb.d), as root over the local socket.
# 1) The app user may use the update trial database (DockerUpdater::TRIAL_DB).
# 2) The root password never appears in the container log: DB_ROOT_PASSWORD (optional) is used as is,
#    otherwise a random one is set and kept only in /var/lib/mysql/.root-password (mode 600).
set -eu
mariadb -uroot ${MARIADB_ROOT_PASSWORD:+-p"$MARIADB_ROOT_PASSWORD"} <<SQL
CREATE DATABASE IF NOT EXISTS ignis_trial;
GRANT ALL ON ignis_trial.* TO 'ignis'@'%';
SQL
if [ -z "${MARIADB_ROOT_PASSWORD:-}" ]; then
    pw="$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    (umask 077; printf '%s\n' "$pw" >/var/lib/mysql/.root-password)
    mariadb -uroot -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$pw'"
fi
