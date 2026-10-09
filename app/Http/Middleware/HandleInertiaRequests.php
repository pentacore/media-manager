<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ActionRequestStatus;
use App\Enums\ServiceType;
use App\Http\Resources\SharedUserResource;
use App\Models\ActionRequest;
use App\Models\EmbyActivity;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Providers\AIServiceProvider;
use App\Services\Library\InterventionCounter;
use App\Services\Library\WantedCounter;
use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use App\Support\Abilities;
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
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? new SharedUserResource($user)->toArray($request) : null,
                'can' => Abilities::for($user),
            ],
            'ai' => [
                'enabled' => AIServiceProvider::enabled(),
            ],
            // Closures: Inertia resolves a shared closure only when the
            // response includes its key, so full visits and prefetches build
            // these while partial reloads (deferred groups, polling, realtime
            // reloads) skip the queries and cache reads entirely. They are
            // built here on every request and capture only this request's
            // user: never move them into a static, a singleton or a
            // cross-request cache (Octane).
            'integrations' => fn (): array => $this->integrations($user),
            'nav' => fn (): array => $user ? $this->navCounts($user) : ['pendingActions' => 0, 'activeSessions' => 0, 'unreadNotifications' => 0, 'libraryIntervention' => 0, 'sabnzbdDownloads' => ['queued' => 0, 'completed' => 0], 'replacementAttention' => 0, 'wantedMissing' => 0],
            'version' => fn (): ?array => $user ? [
                'current' => AppVersion::current(),
                'latest' => AppVersion::latest(),
                'updateAvailable' => AppVersion::updateAvailable(),
            ] : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Which optional integrations have an active connection, read in one
     * query per page visit. Not cached across requests, so an admin's
     * connection change shows on the very next navigation.
     *
     * @return array{seerr: bool, prowlarr: bool, whisparr: bool}
     */
    private function integrations(?User $user): array
    {
        if (! $user instanceof User) {
            return ['seerr' => false, 'prowlarr' => false, 'whisparr' => false];
        }

        /** @var list<ServiceType> $activeTypes */
        $activeTypes = ServiceConnection::query()
            ->where('is_active', true)
            ->whereIn('type', [ServiceType::Seerr, ServiceType::Prowlarr, ServiceType::Whisparr])
            ->distinct()
            ->pluck('type')
            ->all();

        return [
            'seerr' => in_array(ServiceType::Seerr, $activeTypes, true),
            'prowlarr' => in_array(ServiceType::Prowlarr, $activeTypes, true),
            // The Whisparr pages are admin-only, so members never see the link.
            'whisparr' => $user->can(Abilities::ADMIN) && in_array(ServiceType::Whisparr, $activeTypes, true),
        ];
    }

    /**
     * Lightweight counts for sidebar / topbar badges. Cheap queries —
     * indexed columns and bound clauses. Live updates layer on top via
     * the sidebar's WS subscriptions.
     *
     * @return array{pendingActions: int, activeSessions: int, unreadNotifications: int, libraryIntervention: int, sabnzbdDownloads: array{queued: int, completed: int}, replacementAttention: int, wantedMissing: int}
     */
    private function navCounts(User $user): array
    {
        // The library badges (intervention queue, SABnzbd, Wanted) sit on
        // manage-library pages; viewers get constant zeros and never trigger
        // a recompute or an upstream call.
        $canManageLibrary = $user->can(Abilities::MANAGE_LIBRARY);

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
            'libraryIntervention' => $canManageLibrary ? $this->libraryInterventionCount() : 0,
            'sabnzbdDownloads' => $canManageLibrary ? $this->sabnzbdDownloadCounts() : ['queued' => 0, 'completed' => 0],
            // Admin-only surface (Admin → Media Replacement → Attempts); members
            // get a constant zero so the shared shape stays stable.
            'replacementAttention' => $user->isAdmin() ? MediaReplacementAttempt::unacknowledgedAttentionCount() : 0,
            'wantedMissing' => $canManageLibrary ? $this->wantedMissingCount() : 0,
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

    private function wantedMissingCount(): int
    {
        $wantedCounter = resolve(WantedCounter::class);

        if (Cache::has(WantedCounter::CACHE_KEY)) {
            return $wantedCounter->get();
        }

        // Cold cache only (the scheduled library:refresh-wanted-count keeps
        // it warm): warm() lets one request recompute under a short lock with
        // non-retrying calls, and never lets a flaky *arr 500 a page render.
        try {
            return $wantedCounter->warm();
        } catch (Throwable) {
            return 0;
        }
    }
}
