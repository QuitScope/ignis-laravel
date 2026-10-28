#!/usr/bin/env bash
# Docker smoke test: builds and starts the stack under a throwaway project name,
# checks health, installer redirect, non-root, and that APP_KEY survives container
# recreation (encrypted setting stays readable). Always tears down with -v.
set -euo pipefail

cd "$(dirname "$0")/.."

BASE=http://127.0.0.1:8080
ENV_FILE="$(mktemp)"
if command -v cygpath >/dev/null; then ENV_FILE="$(cygpath -m "$ENV_FILE")"; fi
cat >"$ENV_FILE" <<EOF
DB_PASSWORD=smoke-$RANDOM-$RANDOM
UPDATER_SECRET=smoke-$RANDOM-$RANDOM-$RANDOM
APP_URL=$BASE
IGNIS_VERSION=smoke
SESSION_SECURE_COOKIE=false
EOF

dc() { docker compose -p ignis_smoke --env-file "$ENV_FILE" "$@"; }
fail() { echo "SMOKE FAIL: $*" >&2; exit 1; }

cleanup() {
    code=$?
    if [ "$code" -ne 0 ]; then
        echo "---- docker compose logs ----" >&2
        dc logs --no-color --tail=200 >&2 || true
    fi
    dc down -v --remove-orphans >/dev/null 2>&1 || true
    rm -f "$ENV_FILE"
    exit "$code"
}
trap cleanup EXIT

wait_healthy() {
    for _ in $(seq 1 90); do
        id="$(dc ps -q app 2>/dev/null || true)"
        if [ -n "$id" ] && [ "$(docker inspect -f '{{.State.Health.Status}}' "$id" 2>/dev/null)" = healthy ] &&
            curl -fsS -o /dev/null "$BASE/healthz"; then
            return 0
        fi
        sleep 2
    done
    fail "app not healthy within 180s"
}

artisan_php() { dc exec -T app php artisan tinker --execute="$1"; }

echo "==> build + start"
dc up -d --build
wait_healthy

echo "==> /healthz"
health="$(curl -fsS "$BASE/healthz")"
echo "$health"
status="$(printf '%s' "$health" | dc exec -T app php -r '$j = json_decode(stream_get_contents(STDIN), true); echo is_array($j) ? ($j["status"] ?? "") : "";')"
case "$status" in ok | degraded) ;; *) fail "healthz status '$status'" ;; esac

echo "==> / redirects to /install"
redirect="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/")"
[ "$redirect" = "302 $BASE/install" ] || fail "expected 302 to /install, got '$redirect'"

echo "==> /install answers 200"
code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/install")"
[ "$code" = 200 ] || fail "/install returned $code"

echo "==> legacy *.php URLs reach Laravel (nginx docker/nginx/legacy-php.conf)"
v_plain="$(curl -fsS "$BASE/api/version")"
v_php="$(curl -fsS "$BASE/api/version.php")" || fail "/api/version.php failed"
[ "$v_php" = "$v_plain" ] || fail "/api/version.php '$v_php' differs from /api/version '$v_plain'"
ctype="$(curl -s -o /dev/null -w '%{http_code} %{content_type}' -X POST -H 'Content-Type: application/json' \
    -d '{"discord_id":"123456789012345678"}' "$BASE/api/tablet/login-token.php")"
case "$ctype" in 40[13]\ application/json* | 503\ application/json*) ;; *) fail "POST login-token.php: '$ctype'" ;; esac
redirect="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/auth/callback.php?code=x&state=y")"
[ "$redirect" = "302 $BASE/install" ] || fail "/auth/callback.php: '$redirect'"
# Front controller still answers: Laravel sends /index.php/install, nginx 301s that to /install.
final="$(curl -sL -o /dev/null -w '%{http_code} %{url_effective}' "$BASE/index.php")"
[ "$final" = "200 $BASE/install" ] || fail "/index.php: '$final'"

# Prints every X-Frame-Options value of a response, one per line.
xfo() { curl -s -D - -o /dev/null "$@" | tr -d '\r' | sed -n 's/^[Xx]-[Ff]rame-[Oo]ptions: *//p'; }

echo "==> exactly one X-Frame-Options (from the app, not nginx)"
[ "$(xfo "$BASE/install")" = SAMEORIGIN ] || fail "/install X-Frame-Options: '$(xfo "$BASE/install")'"

echo "==> runs as non-root"
for svc in app worker scheduler; do
    # MSYS_NO_PATHCONV: keep Git Bash on Windows from rewriting /proc/1.
    uid="$(MSYS_NO_PATHCONV=1 dc exec -T "$svc" stat -c %u /proc/1)"
    [ "$uid" != 0 ] || fail "$svc PID 1 runs as root"
done

echo "==> hardening: exif, db root password not logged, trial grant"
dc exec -T app php -m | grep -x exif >/dev/null || fail "PHP exif missing"
dc exec -T app php -i | grep -E '^(upload_max_filesize|post_max_size) '
if dc logs db --no-color | grep -qiE 'GENERATED ROOT PASSWORD'; then fail "db log contains a root password"; fi
dc exec -T db sh -c 'test -s /var/lib/mysql/.root-password' || fail "random root password not kept in the db volume"
dc exec -T db sh -c 'mariadb -uignis -p"$MARIADB_PASSWORD" -e "SHOW GRANTS"' | grep 'ignis_trial' >/dev/null || fail "no grant on ignis_trial"

echo "==> image contents"
dc exec -T app test -f public/build/manifest.json || fail "public/build missing"
dc exec -T app test ! -e vendor/pestphp || fail "dev packages in vendor"
dc exec -T app test -s storage/app.key || fail "APP_KEY file not in storage volume"

echo "==> APP_KEY persistence"
out="$(artisan_php "app(App\Support\Settings::class)->set('smoke', 'secret-value', true); echo 'stored';")"
echo "$out" | grep -q stored || fail "could not store encrypted setting: $out"

read_back() {
    artisan_php "echo 'value=' . app(App\Support\Settings::class)->get('smoke');" | grep -q 'value=secret-value' ||
        fail "encrypted setting unreadable after $1"
}

dc up -d --force-recreate app
wait_healthy
read_back "force-recreate app"

dc down
dc up -d
wait_healthy
read_back "down && up"

echo "==> FiveM CEF: no X-Frame-Options for CitizenFX on /auth/tablet (needs an installed app)"
dc exec -T app php artisan ignis:install --no-interaction --org=Smoke --discord-client-id=123456789012345678 \
    --discord-client-secret=smoke-secret --admin-discord-id=123456789012345678 >/dev/null
[ "$(xfo "$BASE/login")" = SAMEORIGIN ] || fail "/login X-Frame-Options: '$(xfo "$BASE/login")'"
[ -z "$(xfo -A CitizenFX/1 "$BASE/auth/tablet?token=x")" ] || fail "CitizenFX /auth/tablet still has X-Frame-Options"

echo "==> response headers of /login (docs/dev/operations.md)"
curl -sI "$BASE/login"

echo "SMOKE OK"
