# Parallel Pest Tests

Run non-browser Pest tests with the `--parallel` flag. Browser tests must run serially and must never use `--parallel`.

Run the browser suite with `vendor/bin/sail composer test:browser`. It starts the Inertia SSR server, waits for it to report healthy and makes SSR errors fatal — the same script CI runs. Without it Inertia falls back to client rendering and the SSR hydration test skips.
