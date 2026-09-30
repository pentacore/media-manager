<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\EmbyUserLink;
use App\Services\Seerr\SeerrUserResolver;

class EmbyUserLinkObserver
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
