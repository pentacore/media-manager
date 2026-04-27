<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Prowlarr\ProwlarrClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ProwlarrTestIndexerController extends Controller
{
    public function __invoke(ServiceConnection $serviceConnection, int $indexerId): RedirectResponse
    {
        throw_if($serviceConnection->type !== ServiceType::Prowlarr, NotFoundHttpException::class);

        try {
            $result = new ProwlarrClient($serviceConnection)->testIndexer($indexerId);
        } catch (Throwable $throwable) {
            Log::warning('Prowlarr indexer test failed', [
                'connection_id' => $serviceConnection->id,
                'indexer_id' => $indexerId,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            Inertia::flash('toast', ['type' => 'error', 'message' => 'Indexer test failed (network error).']);

            return back();
        }

        if ($result['success']) {
            Inertia::flash('toast', ['type' => 'success', 'message' => sprintf('Indexer #%d tested OK.', $indexerId)]);
        } else {
            $first = $result['errors'][0]['errorMessage'] ?? 'Test failed.';
            Inertia::flash('toast', ['type' => 'error', 'message' => sprintf('Indexer #%d: %s', $indexerId, $first)]);
        }

        return back();
    }
}
