<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ActionRequestStatus;
use App\Http\Resources\SharedUserResource;
use App\Models\ActionRequest;
use App\Models\EmbyActivity;
use App\Models\MediaReplacementAttempt;
use App\Models\User;
use App\Providers\AIServiceProvider;
use App\Services\Library\InterventionCounter;
use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use App\Support\AppVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use Override;
use Throwable;

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
            'nav' => $user ? $this->navCounts($user) : ['pendingActions' => 0, 'activeSessions' => 0, 'unreadNotifications' => 0, 'libraryIntervention' => 0, 'sabnzbdDownloads' => ['queued' => 0, 'completed' => 0], 'replacementAttention' => 0],
            'version' => $user ? [
                'current' => AppVersion::current(),
                'latest' => AppVersion::latest(),
                'updateAvailable' => AppVersion::updateAvailable(),
            ] : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Lightweight counts for sidebar / topbar badges. Cheap queries —
     * indexed columns and bound clauses. Live updates layer on top via
     * the sidebar's WS subscriptions.
     *
     * @return array{pendingActions: int, activeSessions: int, unreadNotifications: int, libraryIntervention: int, sabnzbdDownloads: array{queued: int, completed: int}, replacementAttention: int}
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
            // immediate recompute when a stuck import lands. On a cold
            // boot (cache empty, scheduler not yet ticked) we recompute
            // inline once so the badge isn't silently zero for the first
            // five minutes after deploy.
            'libraryIntervention' => $this->libraryInterventionCount(),
            'sabnzbdDownloads' => $this->sabnzbdDownloadCounts(),
            // Admin-only surface (Admin → Media Replacement → Attempts); members
            // get a constant zero so the shared shape stays stable.
            'replacementAttention' => $user->isAdmin() ? MediaReplacementAttempt::unacknowledgedAttentionCount() : 0,
        ];
    }

    private function libraryInterventionCount(): int
    {
        $interventionCounter = resolve(InterventionCounter::class);

        if (Cache::has(InterventionCounter::CACHE_KEY)) {
            return $interventionCounter->get();
        }

        // recompute() walks Sonarr+Radarr APIs; in environments without
        // a running scheduler this is the first warm-up. Anything that
        // throws (Http::preventStrayRequests in tests, an upstream
        // outage in prod) is swallowed so a flaky *arr doesn't 500
        // every page render — the badge just stays at zero.
        try {
            return $interventionCounter->recompute();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array{queued: int, completed: int}
     */
    private function sabnzbdDownloadCounts(): array
    {
        $sabnzbdDownloadCounter = resolve(SabnzbdDownloadCounter::class);

        if (Cache::has(SabnzbdDownloadCounter::CACHE_KEY)) {
            return $sabnzbdDownloadCounter->get();
        }

        try {
            return $sabnzbdDownloadCounter->recompute();
        } catch (Throwable) {
            return ['queued' => 0, 'completed' => 0];
        }
    }
}
