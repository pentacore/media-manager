<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleInventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class LibraryController extends BazarrController
{
    public function __invoke(Request $request, SubtitleInventoryService $subtitleInventoryService): Response
    {
        $validated = $request->validate([
            ...$this->commonRules(),
            'media_type' => ['nullable', 'in:episode,movie'],
            'scope' => ['nullable', 'in:anime,tv,movie'],
            'missing_only' => ['nullable', 'boolean'],
        ]);
        $connectionProps = $this->connectionProps($request);
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
                    fn (): array => $subtitleInventoryService->library($connection, $page, $perPage, $filters),
                )
                : null,
        ]);
    }
}
