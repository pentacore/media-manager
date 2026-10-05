<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleLibraryReader;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OverviewController extends BazarrController
{
    public function __invoke(Request $request, SubtitleLibraryReader $subtitleLibraryReader): Response
    {
        $request->validate($this->commonRules());
        $connectionProps = $this->connectionProps($request);
        $connection = $this->selectedConnection($connectionProps);

        return Inertia::render('Bazarr/Overview', [
            ...$connectionProps,
            'overview' => $connection instanceof ServiceConnection ? $subtitleLibraryReader->overview($connection) : null,
        ]);
    }
}
