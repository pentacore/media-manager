<?php

declare(strict_types=1);

namespace App\Http\Controllers\Library;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Library\WantedRequest;
use App\Services\Library\LibraryWanted;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Missing and cutoff-unmet items from the primary Sonarr and Radarr, paged
 * upstream by LibraryWanted. Remote-service listing, so the props envelope
 * is built there.
 */
class WantedController extends Controller
{
    public function __invoke(WantedRequest $wantedRequest, LibraryWanted $libraryWanted): Response
    {
        $validated = $wantedRequest->validated();
        $tab = (string) ($validated['tab'] ?? 'missing');
        $monitored = (bool) ($validated['monitored'] ?? true);
        $sonarrPage = (int) ($validated['sonarr_page'] ?? 1);
        $radarrPage = (int) ($validated['radarr_page'] ?? 1);

        return Inertia::render('Library/Wanted', [
            'filters' => ['tab' => $tab, 'monitored' => $monitored, 'sonarr_page' => $sonarrPage, 'radarr_page' => $radarrPage],
            'sonarr' => Inertia::defer(fn (): array => $libraryWanted->section(ServiceType::Sonarr, $tab, $sonarrPage, $monitored), 'sonarr'),
            'radarr' => Inertia::defer(fn (): array => $libraryWanted->section(ServiceType::Radarr, $tab, $radarrPage, $monitored), 'radarr'),
        ]);
    }
}
