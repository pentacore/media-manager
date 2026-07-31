<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Enums\SubtitleCaseStatus;
use App\Models\SubtitleCase;
use App\Settings\BazarrAutomationSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class SubtitleCaseReconciler
{
    private const array ACTIVE_STATUSES = [
        SubtitleCaseStatus::Observing,
        SubtitleCaseStatus::BazarrSearching,
        SubtitleCaseStatus::DownloadRequested,
        SubtitleCaseStatus::ReplacementEligible,
        SubtitleCaseStatus::AdvisorRunning,
        SubtitleCaseStatus::ReplacementRequested,
        SubtitleCaseStatus::NeedsReview,
    ];

    public function __construct(
        private BazarrAutomationSettings $bazarrAutomationSettings,
        private SubtitleCaseLifecycle $subtitleCaseLifecycle,
    ) {}

    /**
     * @param  array<string, mixed>  $candidate
     */
    public function reconcile(array $candidate): ?SubtitleCase
    {
        $candidate = $this->validatedCandidate($candidate);
        $lockKey = sprintf(
            'bazarr-subtitle-case:%d:%d:%s',
            $candidate['bazarr_connection_id'],
            $candidate['service_connection_id'],
            hash('sha256', json_encode($candidate['target_ids'], JSON_THROW_ON_ERROR)),
        );

        return Cache::lock($lockKey, 15)->block(
            5,
            fn (): ?SubtitleCase => DB::transaction(
                fn (): ?SubtitleCase => $this->reconcileLocked($candidate),
                attempts: 3,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function reconcileLocked(array $candidate): ?SubtitleCase
    {
        $existing = $this->identityQuery($candidate)->lockForUpdate()->first();
        $isComplete = $candidate['missing_languages'] === [] || ! $candidate['monitored'];

        // Any other active case for this target describes a file identity that is no
        // longer installed, so it is retired on every path — including when this
        // identity is closed. Otherwise a dismissed identity that comes back (A
        // dismissed, file becomes B, file reverts to A) would leave the active B case
        // offering actions against a file that no longer exists.
        $staleTargetCases = $this->activeTargetCases($candidate)
            ->reject(fn (SubtitleCase $subtitleCase): bool => $existing instanceof SubtitleCase
                && $subtitleCase->is($existing))
            ->values();

        if ($isComplete) {
            foreach ($staleTargetCases as $staleTargetCase) {
                $this->subtitleCaseLifecycle->resolve($staleTargetCase, [
                    'evidence' => $this->evidence($candidate, $staleTargetCase),
                    'observed_at' => now(),
                ]);
            }

            if (! $existing instanceof SubtitleCase) {
                return $staleTargetCases->first();
            }

            if (in_array($existing->status, self::ACTIVE_STATUSES, true)) {
                $this->subtitleCaseLifecycle->resolve($existing, [
                    'evidence' => $this->evidence($candidate, $existing),
                    'observed_at' => now(),
                ]);
            }

            return $existing;
        }

        foreach ($staleTargetCases as $staleTargetCase) {
            $this->subtitleCaseLifecycle->supersede($staleTargetCase);
        }

        if ($existing instanceof SubtitleCase) {
            // A resolved requirement can go missing again — the subtitle is deleted
            // while the file and language profile stay put. The resolved row cannot
            // re-enter an active state and its identity blocks a replacement, so it
            // is superseded here and a fresh case observes the identity below.
            // Explicitly closed identities (dismissed, handled) stay closed.
            if ($existing->status !== SubtitleCaseStatus::Resolved) {
                $existing->forceFill([
                    'evidence' => $this->evidence($candidate, $existing),
                    'observed_at' => now(),
                ])->save();
                $this->advanceElapsedGrace($existing);

                return $existing;
            }

            $this->subtitleCaseLifecycle->supersede($existing);
        }

        // Superseded rows keep their identity columns but are out of the partial
        // unique index, so the lookup — like the index — only considers cases still
        // on the record and a fresh case can observe a reopened identity.
        $subtitleCase = $this->identityQuery($candidate)->lockForUpdate()->first()
            ?? SubtitleCase::query()->create([
                'bazarr_connection_id' => $candidate['bazarr_connection_id'],
                'service_connection_id' => $candidate['service_connection_id'],
                'file_fingerprint' => $candidate['file_fingerprint'],
                'requirements_fingerprint' => $candidate['requirements_fingerprint'],
                'media_type' => $candidate['media_type'],
                'scope' => $candidate['scope'],
                'target_ids' => $candidate['target_ids'],
                'required_languages' => $this->requirements($candidate['required_languages']),
                'status' => SubtitleCaseStatus::Observing,
                'evidence' => $this->evidence($candidate),
                'grace_until' => now()->addHours($this->bazarrAutomationSettings->graceHours($candidate['scope'])),
                'observed_at' => now(),
            ]);

        $this->advanceElapsedGrace($subtitleCase);

        return $subtitleCase;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return Builder<SubtitleCase>
     */
    private function identityQuery(array $candidate): Builder
    {
        return SubtitleCase::query()
            ->where('bazarr_connection_id', $candidate['bazarr_connection_id'])
            ->where('service_connection_id', $candidate['service_connection_id'])
            ->where('file_fingerprint', $candidate['file_fingerprint'])
            ->where('requirements_fingerprint', $candidate['requirements_fingerprint'])
            ->whereNot('status', SubtitleCaseStatus::Superseded);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return Collection<int, SubtitleCase>
     */
    /**
     * The target predicates belong in SQL. Locking every active case for the whole
     * connection pairing and filtering afterwards made two workers reconciling
     * different titles lock each other's rows in opposite order — an ordinary
     * multi-case sweep then deadlocked — and hydrated the entire active backlog once
     * per candidate.
     *
     * @param  array<string, mixed>  $candidate
     * @return Collection<int, SubtitleCase>
     */
    private function activeTargetCases(array $candidate): Collection
    {
        $isEpisode = $candidate['media_type'] === 'episode';
        $targetIds = $candidate['target_ids'];
        $requiredKeys = $isEpisode ? ['series_id', 'episode_id'] : ['radarr_id'];

        foreach ($requiredKeys as $requiredKey) {
            // Without a concrete target there is nothing to scope the lock to, and
            // locking the whole pairing is exactly what this avoids.
            if (! is_int($targetIds[$requiredKey] ?? null)) {
                return new Collection;
            }
        }

        return SubtitleCase::query()
            ->where('bazarr_connection_id', $candidate['bazarr_connection_id'])
            ->where('service_connection_id', $candidate['service_connection_id'])
            ->where('media_type', $candidate['media_type'])
            ->whereIn('status', array_map(
                static fn (SubtitleCaseStatus $subtitleCaseStatus): string => $subtitleCaseStatus->value,
                self::ACTIVE_STATUSES,
            ))
            ->when(
                $isEpisode,
                fn (Builder $builder): Builder => $builder
                    ->where('target_ids->series_id', $targetIds['series_id'])
                    ->where('target_ids->episode_id', $targetIds['episode_id']),
                fn (Builder $builder): Builder => $builder
                    ->where('target_ids->radarr_id', $targetIds['radarr_id']),
            )
            ->lockForUpdate()
            ->get();
    }

    private function advanceElapsedGrace(SubtitleCase $subtitleCase): void
    {
        if ($subtitleCase->status !== SubtitleCaseStatus::Observing
            || $subtitleCase->grace_until === null
            || $subtitleCase->grace_until->isFuture()) {
            return;
        }

        $this->subtitleCaseLifecycle->transition($subtitleCase, SubtitleCaseStatus::BazarrSearching);
    }

    /**
     * @param  list<string>  $languages
     * @return list<array{code: string, forced: bool, hearing_impaired: bool}>
     */
    private function requirements(array $languages): array
    {
        return array_map(
            static fn (string $language): array => [
                'code' => $language,
                'forced' => false,
                'hearing_impaired' => false,
            ],
            $languages,
        );
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function evidence(array $candidate, ?SubtitleCase $existing = null): array
    {
        $evidence = [
            'display_name' => $candidate['display_name'],
            'missing_languages' => $candidate['missing_languages'],
            'current_subtitles' => $candidate['current_subtitles'],
            'monitored' => $candidate['monitored'],
        ];

        // Preserve the per-language download request map that the download
        // request creator writes; a fresh projection must never clobber it.
        $downloadRequests = $existing?->evidence['download_requests'] ?? null;

        if (is_array($downloadRequests) && $downloadRequests !== []) {
            $evidence['download_requests'] = $downloadRequests;
        }

        return $evidence;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function validatedCandidate(array $candidate): array
    {
        foreach (['bazarr_connection_id', 'service_connection_id'] as $key) {
            throw_unless(is_int($candidate[$key] ?? null) && $candidate[$key] > 0, InvalidArgumentException::class, 'Subtitle case candidate IDs are invalid.');
        }

        throw_unless(in_array($candidate['media_type'] ?? null, ['episode', 'movie'], true), InvalidArgumentException::class, 'Subtitle case candidate media type is invalid.');
        throw_unless(in_array($candidate['scope'] ?? null, ['anime', 'tv', 'movie'], true), InvalidArgumentException::class, 'Subtitle case candidate scope is invalid.');
        throw_unless(is_array($candidate['target_ids'] ?? null), InvalidArgumentException::class, 'Subtitle case candidate target IDs are invalid.');

        foreach (['file_fingerprint', 'requirements_fingerprint'] as $key) {
            throw_unless(is_string($candidate[$key] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $candidate[$key]) === 1, InvalidArgumentException::class, 'Subtitle case candidate fingerprints are invalid.');
        }

        foreach (['required_languages', 'missing_languages', 'current_subtitles'] as $key) {
            throw_unless(is_array($candidate[$key] ?? null), InvalidArgumentException::class, 'Subtitle case candidate languages are invalid.');
            $candidate[$key] = array_values(array_filter($candidate[$key], is_string(...)));
        }

        $candidate['display_name'] = is_string($candidate['display_name'] ?? null)
            ? mb_substr($candidate['display_name'], 0, 300)
            : 'Subtitle case';
        $candidate['monitored'] = ($candidate['monitored'] ?? false) === true;

        return $candidate;
    }
}
