#!/usr/bin/env bash
set -euo pipefail

role="${CONTAINER_ROLE:-web}"

case "$role" in
    web)
        curl -fsS http://127.0.0.1:8080/up > /dev/null
        ;;
    reverb)
        # Reverb responds to HTTP on its app port; any 2xx/3xx/4xx means the server is up
        curl -fsS -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/ | grep -qE '^[234]'
        ;;
    queue|scheduler|horizon)
        # Process-level liveness: PID 1 is tini → entrypoint → artisan; presence of artisan suffices
        pgrep -f 'artisan' > /dev/null
        ;;
    *)
        exit 0
        ;;
esac
