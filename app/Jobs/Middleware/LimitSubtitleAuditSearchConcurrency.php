<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Jobs\SearchSubtitleAuditEpisode;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * At most two subtitle audit release searches run against one Sonarr
 * connection at a time; a job finding both slots taken goes back on the queue.
 * The slot outlives the job timeout, so a killed worker frees it on its own.
 */
final readonly class LimitSubtitleAuditSearchConcurrency
{
    public const int SLOTS = 2;

    private const int RELEASE_AFTER_SECONDS = 240;

    private const int RETRY_AFTER_SECONDS = 10;

    public function handle(SearchSubtitleAuditEpisode $searchSubtitleAuditEpisode, Closure $next): mixed
    {
        $serviceConnectionId = $searchSubtitleAuditEpisode->serviceConnectionId();

        if ($serviceConnectionId === null) {
            return $next($searchSubtitleAuditEpisode);
        }

        return Cache::funnel(sprintf('subtitle-audit-search:%d', $serviceConnectionId))
            ->limit(self::SLOTS)
            ->releaseAfter(self::RELEASE_AFTER_SECONDS)
            ->block(0)
            ->then(
                fn (): mixed => $next($searchSubtitleAuditEpisode),
                function () use ($searchSubtitleAuditEpisode): void {
                    $searchSubtitleAuditEpisode->release(self::RETRY_AFTER_SECONDS);
                },
            );
    }
}
