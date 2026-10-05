<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Http\Requests\Bazarr\OverviewPageRequest;
use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleLibraryReader;
use Inertia\Inertia;
use Inertia\Response;

final class OverviewController extends BazarrController
{
    public function __invoke(OverviewPageRequest $overviewPageRequest, SubtitleLibraryReader $subtitleLibraryReader): Response
    {
        $connectionProps = $this->connectionProps($overviewPageRequest);
        $connection = $this->selectedConnection($connectionProps);

        return Inertia::render('Bazarr/Overview', [
            ...$connectionProps,
            'overview' => $connection instanceof ServiceConnection ? $subtitleLibraryReader->overview($connection) : null,
        ]);
    }
}
