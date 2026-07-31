<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleInventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class HistoryController extends BazarrController
{
    public function __invoke(Request $request, SubtitleInventoryService $subtitleInventoryService): Response
    {
        $validated = $request->validate([
            ...$this->commonRules(),
            'media_type' => ['nullable', 'in:episode,movie'],
            'provider' => ['nullable', 'string', 'max:100'],
        ]);
        $connectionProps = $this->connectionProps($request);
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
                    fn (): array => $subtitleInventoryService->history($connection, $page, $perPage, $filters),
                )
                : null,
        ]);
    }
}
