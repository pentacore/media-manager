#!/usr/bin/env bash
set -euo pipefail

cd /app

# Allow custom command override (e.g. `docker run image php artisan tinker`)
if [[ $# -gt 0 ]]; then
    exec "$@"
fi

role="${CONTAINER_ROLE:-web}"

# Trust APP_KEY from env. Fail fast if missing in production.
if [[ "${APP_ENV:-production}" == "production" && -z "${APP_KEY:-}" ]]; then
    echo "FATAL: APP_KEY environment variable is required in production." >&2
    exit 1
fi

# Warm caches at boot — config/route/view/event must reflect runtime env, not build env.
warm_caches() {
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
}

if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
    # --isolated takes a DB-level atomic lock so concurrent web replicas don't
    # race the migrations table. The dedicated `migrate` role below is still
    # the recommended pattern for multi-replica deployments.
    echo "Running migrations..."
    php artisan migrate --force --isolated
fi

case "$role" in
    web)
        warm_caches
        # Idempotent symlink — `--force` would unlink+symlink and race other
        # web replicas sharing a storage volume, briefly 404-ing public assets.
        if [[ ! -L public/storage ]]; then
            php artisan storage:link || true
        fi
        # Octane worker mode on FrankenPHP: framework boots once per worker and is reused
        # across requests. --workers=auto sizes to CPU count; --max-requests recycles workers
        # to bound any per-request state leaks. Octane generates its own Caddyfile in
        # storage/octane/frankenphp/ at startup.
        exec php artisan octane:start \
            --server=frankenphp \
            --host=0.0.0.0 \
            --port=8080 \
            --workers=auto \
            --max-requests=500 \
            --log-level=info \
            --no-interaction
        ;;

    queue)
        warm_caches
        exec php artisan queue:work \
            --sleep=3 \
            --tries=3 \
            --max-time=3600 \
            --timeout=300 \
            --no-interaction \
            --verbose
        ;;

    scheduler)
        warm_caches
        # schedule:work is a long-running supervisor that ticks the scheduler every minute
        exec php artisan schedule:work --no-interaction
        ;;

    reverb)
        warm_caches
        exec php artisan reverb:start --host=0.0.0.0 --port=8080 --no-interaction
        ;;

    horizon)
        warm_caches
        exec php artisan horizon
        ;;

    migrate)
        exec php artisan migrate --force
        ;;

    *)
        echo "Unknown CONTAINER_ROLE: $role" >&2
        echo "Valid roles: web, queue, scheduler, reverb, horizon, migrate" >&2
        exit 1
        ;;
esac
