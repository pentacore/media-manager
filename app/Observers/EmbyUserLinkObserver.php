<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\EmbyUserLink;
use App\Services\Seerr\SeerrUserResolver;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * A new or removed Emby link can change which Seerr user its app user
 * matches, so the cached match is forgotten — after commit: forgetting it
 * inside the open transaction let another request re-cache the old match
 * from the pre-commit state for the cache's lifetime.
 */
class EmbyUserLinkObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly SeerrUserResolver $seerrUserResolver) {}

    public function created(EmbyUserLink $embyUserLink): void
    {
        $this->seerrUserResolver->forgetMatchCache($embyUserLink->user);
    }

    public function deleted(EmbyUserLink $embyUserLink): void
    {
        $this->seerrUserResolver->forgetMatchCache($embyUserLink->user);
    }
}
