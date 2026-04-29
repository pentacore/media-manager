<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ActionRequestStatus;
use App\Http\Resources\SharedUserResource;
use App\Models\ActionRequest;
use App\Models\EmbyActivity;
use App\Models\User;
use App\Providers\AIServiceProvider;
use App\Services\Library\InterventionCounter;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Override;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    #[Override]
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    #[Override]
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? new SharedUserResource($user)->toArray($request) : null,
            ],
            'ai' => [
                'enabled' => AIServiceProvider::enabled(),
            ],
            'nav' => $user ? $this->navCounts($user) : ['pendingActions' => 0, 'activeSessions' => 0, 'unreadNotifications' => 0, 'libraryIntervention' => 0],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Lightweight counts for sidebar / topbar badges. Cheap queries —
     * indexed columns and bound clauses. Live updates layer on top via
     * the sidebar's WS subscriptions.
     *
     * @return array{pendingActions: int, activeSessions: int, unreadNotifications: int, libraryIntervention: int}
     */
    private function navCounts(User $user): array
    {
        return [
            'pendingActions' => ActionRequest::where('status', ActionRequestStatus::Pending)->count(),
            'activeSessions' => EmbyActivity::where('action', 'played')
                ->where('updated_at', '>=', now()->subMinutes(10))
                ->count(),
            'unreadNotifications' => $user->unreadNotifications()->count(),
            // Backed by a cache so this stays cheap; the scheduled job
            // refreshes the value every 5 minutes and webhooks force an
            // immediate recompute when a stuck import lands.
            'libraryIntervention' => resolve(InterventionCounter::class)->get(),
        ];
    }
}
