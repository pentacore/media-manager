<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Jobs\RunSubtitleAdvisor;
use App\Settings\BazarrAutomationSettings;
use Closure;
use Illuminate\Support\Facades\Cache;

final readonly class LimitSubtitleAdvisorConcurrency
{
    private const int RELEASE_AFTER_SECONDS = 240;

    private const int RETRY_AFTER_SECONDS = 10;

    public function handle(RunSubtitleAdvisor $runSubtitleAdvisor, Closure $next): mixed
    {
        return Cache::funnel('bazarr-advisor')
            ->limit(resolve(BazarrAutomationSettings::class)->advisorConcurrency())
            ->releaseAfter(self::RELEASE_AFTER_SECONDS)
            ->block(0)
            ->then(
                fn (): mixed => $next($runSubtitleAdvisor),
                fn (): mixed => $runSubtitleAdvisor->release(self::RETRY_AFTER_SECONDS),
            );
    }
}
