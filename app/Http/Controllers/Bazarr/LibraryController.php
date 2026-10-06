<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Http\Requests\Bazarr\LibraryPageRequest;
use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleLibraryReader;
use Inertia\Inertia;
use Inertia\Response;

final class LibraryController extends BazarrController
{
    public function __invoke(LibraryPageRequest $libraryPageRequest, SubtitleLibraryReader $subtitleLibraryReader): Response
    {
        $validated = $libraryPageRequest->validated();
        $connectionProps = $this->connectionProps($libraryPageRequest);
        $connection = $this->selectedConnection($connectionProps);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $filters = array_filter([
            'media_type' => $validated['media_type'] ?? null,
            'scope' => $validated['scope'] ?? null,
            'missing_only' => isset($validated['missing_only']) ? (bool) $validated['missing_only'] : null,
        ], static fn (mixed $value): bool => $value !== null);

        return Inertia::render('Bazarr/Library', [
            ...$connectionProps,
            'filters' => ['page' => $page, 'per_page' => $perPage, ...$filters],
            'library' => $connection instanceof ServiceConnection
                ? Inertia::defer(
                    fn (): array => $subtitleLibraryReader->library($connection, $page, $perPage, $filters),
                )
                : null,
        ]);
    }
}
