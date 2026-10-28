#!/bin/sh
# Wraps the serversideup entrypoint. Its /etc/entrypoint.d hooks run in subshells and
# cannot export variables, so APP_KEY is resolved here and handed on via the environment.
set -eu
cd /var/www/html

# The storage volume may be fresh or older than this image.
mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# APP_KEY: an explicitly set value wins; otherwise generate once and keep it in the volume.
if [ -z "${APP_KEY:-}" ]; then
    key_file=storage/app.key
    if [ ! -s "$key_file" ]; then
        tmp="$(mktemp storage/.app.key.XXXXXX)"
        php -r 'echo "base64:".base64_encode(random_bytes(32));' >"$tmp"
        # Hard link = atomic create-if-absent; a container that loses the race uses the winner's key.
        if ln "$tmp" "$key_file" 2>/dev/null; then
            cat >&2 <<'MSG'
WARNING: storage/app.key was missing, generated a NEW APP_KEY.
         Harmless on the very first start. If this is not the first start, encrypted
         settings (Discord client secret, API keys) are now unreadable: restore the old
         storage/app.key or set APP_KEY in .env.
MSG
        fi
        rm -f "$tmp"
    fi
    APP_KEY="$(cat "$key_file")"
    export APP_KEY
fi

# Reverb (realtime, opt-in): with BROADCAST_CONNECTION=reverb and no REVERB_APP_* in .env, generate them once into
# the volume, shared by app, worker, scheduler and the reverb service (same atomic create-if-absent as APP_KEY).
# One line "<app id> <key> <secret>", read as data (never sourced). Explicit values in .env win.
if [ "${BROADCAST_CONNECTION:-}" = "reverb" ] && { [ -z "${REVERB_APP_ID:-}" ] || [ -z "${REVERB_APP_KEY:-}" ] || [ -z "${REVERB_APP_SECRET:-}" ]; }; then
    reverb_file=storage/reverb.keys
    if [ ! -s "$reverb_file" ]; then
        tmp="$(mktemp storage/.reverb.keys.XXXXXX)"
        php -r 'echo random_int(100000, 999999)." ".bin2hex(random_bytes(16))." ".bin2hex(random_bytes(32));' >"$tmp"
        ln "$tmp" "$reverb_file" 2>/dev/null || true
        rm -f "$tmp"
    fi
    read -r gen_id gen_key gen_secret <"$reverb_file" || true
    REVERB_APP_ID="${REVERB_APP_ID:-$gen_id}"
    REVERB_APP_KEY="${REVERB_APP_KEY:-$gen_key}"
    REVERB_APP_SECRET="${REVERB_APP_SECRET:-$gen_secret}"
    export REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET
fi

exec docker-php-serversideup-entrypoint "$@"
