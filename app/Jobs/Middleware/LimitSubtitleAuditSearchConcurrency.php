<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Jobs\SearchSubtitleAuditEpisode;
use App\Models\ServiceConnection;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * At most the connection's search slots (ServiceConnection::
 * subtitleAuditSearchSlots(), 2 unless set on the Sonarr connection's edit
 * page) of subtitle audit release searches run against one Sonarr connection
 * at a time; a job finding every slot taken goes back on the queue. A deleted
 * connection falls back to the default. A slot is held for at most
 * RELEASE_AFTER_SECONDS, longer than the job's 270 s timeout, so a running
 * search never loses its slot to another one, and the slot of a worker killed
 * mid-search frees itself once that time has passed.
 */
final readonly class LimitSubtitleAuditSearchConcurrency
{
    public const int RELEASE_AFTER_SECONDS = 300;

    private const int RETRY_AFTER_SECONDS = 10;

    public function handle(SearchSubtitleAuditEpisode $searchSubtitleAuditEpisode, Closure $next): mixed
    {
        $serviceConnectionId = $searchSubtitleAuditEpisode->serviceConnectionId();

        if ($serviceConnectionId === null) {
            return $next($searchSubtitleAuditEpisode);
        }

        return Cache::funnel(sprintf('subtitle-audit-search:%d', $serviceConnectionId))
            ->limit($this->slots($serviceConnectionId))
            ->releaseAfter(self::RELEASE_AFTER_SECONDS)
            ->block(0)
            ->then(
                fn (): mixed => $next($searchSubtitleAuditEpisode),
                function () use ($searchSubtitleAuditEpisode): void {
                    $searchSubtitleAuditEpisode->release(self::RETRY_AFTER_SECONDS);
                },
            );
    }

    private function slots(int $serviceConnectionId): int
    {
        return ServiceConnection::query()->find($serviceConnectionId, ['id', 'settings'])?->subtitleAuditSearchSlots()
            ?? ServiceConnection::DEFAULT_SUBTITLE_AUDIT_SEARCH_SLOTS;
    }
}
