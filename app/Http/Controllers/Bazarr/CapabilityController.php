<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Bazarr\BazarrClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Capability discovery on its own endpoint. Operations must stay disabled until
 * their flag is known, and learning the flags through the manual search response
 * cannot work on a Bazarr version without manual search — that request fails
 * before it can report the capability that explains the failure.
 */
final class CapabilityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'connection' => ['required', 'integer', 'min:1'],
        ]);
        $connection = ServiceConnection::query()
            ->whereKey((int) $validated['connection'])
            ->where('type', ServiceType::Bazarr)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json([
            'capabilities' => new BazarrClient($connection)->getCapabilities(),
        ]);
    }
}
