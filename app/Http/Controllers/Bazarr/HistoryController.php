<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Http\Requests\Bazarr\HistoryPageRequest;
use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleLibraryReader;
use Inertia\Inertia;
use Inertia\Response;

final class HistoryController extends BazarrController
{
    public function __invoke(HistoryPageRequest $historyPageRequest, SubtitleLibraryReader $subtitleLibraryReader): Response
    {
        $validated = $historyPageRequest->validated();
        $connectionProps = $this->connectionProps($historyPageRequest);
        $connection = $this->selectedConnection($connectionProps);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $filters = array_filter([
            'media_type' => $validated['media_type'] ?? null,
            'provider' => $validated['provider'] ?? null,
        ]);

        return Inertia::render('Bazarr/History', [
            ...$connectionProps,
            'filters' => ['page' => $page, 'per_page' => $perPage, ...$filters],
            'history' => $connection instanceof ServiceConnection
                ? Inertia::defer(
                    fn (): array => $subtitleLibraryReader->history($connection, $page, $perPage, $filters),
                )
                : null,
        ]);
    }
}
