<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyMetricsToken;
use Spatie\Prometheus\Actions\RenderCollectorsAction;
use Spatie\Prometheus\Http\Middleware\AllowIps;

return [
    'enabled' => (bool) env('METRICS_ENABLED', true),

    /*
     * The urls that will return metrics.
     */
    'urls' => [
        'default' => 'metrics',
    ],

    /*
     * Only these IP's will be allowed to visit the above urls.
     * All IP's are allowed when empty.
     */
    'allowed_ips' => array_filter(array_map(trim(...), explode(',', (string) env('METRICS_ALLOWED_IPS', '')))),

    /*
     * This is the default namespace that will be
     * used by all metrics. Empty so metric names are emitted verbatim
     * (e.g. `mediamanager_service_up`) with no extra prefix.
     */
    'default_namespace' => '',

    /*
     * The middleware that will be applied to the urls above. The token gate
     * runs first (deny-by-default when unconfigured), then the optional
     * IP allowlist.
     */
    'middleware' => [
        VerifyMetricsToken::class,
        AllowIps::class,
    ],

    /*
     * You can override these classes to customize low-level behaviour of the package.
     * In most cases, you can just use the defaults.
     */
    'actions' => [
        'render_collectors' => RenderCollectorsAction::class,
    ],

    /**
     * Allow storage to be wiped after a render of data in metrics controller.
     */
    'wipe_storage_after_rendering' => false,

    /**
     * Select a cache to store gauges, counters, summaries and histograms between requests.
     * In a multi node setup you should ensure that each node writes to its own
     * cache instance or uses a node specific prefix.
     * Configure the cache store in config/cache.php.
     *
     * to use an in memory adapter for testing use array or null as your store
     * or remove the cache entry all together:
     *  'cache' => null       // InMemory implementation without laravel cache
     *  'cache' => 'array'    // InMemory implementation using laravel cache
     */
    'cache' => null,
];
