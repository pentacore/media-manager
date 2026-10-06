<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Http\Requests\Bazarr\MissingPageRequest;
use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleLibraryReader;
use Inertia\Inertia;
use Inertia\Response;

final class MissingController extends BazarrController
{
    public function __invoke(MissingPageRequest $missingPageRequest, SubtitleLibraryReader $subtitleLibraryReader): Response
    {
        $validated = $missingPageRequest->validated();
        $connectionProps = $this->connectionProps($missingPageRequest);
        $connection = $this->selectedConnection($connectionProps);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $filters = array_filter([
            'media_type' => $validated['media_type'] ?? null,
            'scope' => $validated['scope'] ?? null,
        ]);

        return Inertia::render('Bazarr/Missing', [
            ...$connectionProps,
            'filters' => ['page' => $page, 'per_page' => $perPage, ...$filters],
            'missing' => $connection instanceof ServiceConnection
                ? $subtitleLibraryReader->missing($connection, $page, $perPage, $filters)
                : null,
        ]);
    }
}
