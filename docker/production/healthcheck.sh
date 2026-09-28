#!/usr/bin/env bash
set -euo pipefail

role="${CONTAINER_ROLE:-web}"

case "$role" in
    web)
        curl -fsS http://127.0.0.1:8080/up > /dev/null
        ;;
    reverb)
        # Reverb responds to HTTP on its app port; any 2xx/3xx/4xx means the server is up
        curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/ | grep -qE '^[234]'
        ;;
    ssr)
        php artisan inertia:check-ssr --no-interaction > /dev/null
        ;;
    queue)
        # Heartbeats are queued by the scheduler every minute and recorded by
        # the worker that drains each lane; a lane can sit behind one 300s job.
        php artisan ops:check-heartbeat --queue="${QUEUE_LANES:-actions,webhooks,default,ai,maintenance}" --max-age="${HEARTBEAT_MAX_AGE:-600}" --no-interaction > /dev/null
        ;;
    queue-ai)
        # Checks both lanes this worker drains (ai, maintenance).
        php artisan ops:check-heartbeat --queue=ai,maintenance --max-age="${HEARTBEAT_MAX_AGE:-600}" --no-interaction > /dev/null
        ;;
    scheduler)
        php artisan ops:check-heartbeat --scheduler --max-age="${HEARTBEAT_MAX_AGE:-180}" --no-interaction > /dev/null
        ;;
    *)
        exit 0
        ;;
esac
