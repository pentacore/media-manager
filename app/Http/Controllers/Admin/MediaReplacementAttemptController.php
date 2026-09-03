<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\MediaReplacementScope;
use App\Enums\MediaReplacementStatus;
use App\Http\Controllers\Controller;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MediaReplacementAttemptController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $scope = $request->string('scope')->toString();
        $serviceId = $request->integer('service_id');
        $search = trim($request->string('search')->toString());
        $unacknowledged = $request->boolean('unacknowledged');

        // Unknown filter values are dropped, not matched — a stale link must
        // never render an empty page that looks like "no attempts".
        $status = in_array($status, MediaReplacementStatus::values(), true) ? $status : '';
        $scope = in_array($scope, MediaReplacementScope::values(), true) ? $scope : '';

        $lengthAwarePaginator = $this->buildBuilder($status, $scope, $serviceId, $search, $unacknowledged)
            ->with(['serviceConnection:id,name,type', 'actionRequest:id,status'])
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/MediaReplacement/Attempts/Index', [
            'attempts' => [
                'data' => $lengthAwarePaginator->getCollection()->map($this->row(...))->all(),
                'links' => $lengthAwarePaginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $lengthAwarePaginator->currentPage(),
                    'last_page' => $lengthAwarePaginator->lastPage(),
                    'total' => $lengthAwarePaginator->total(),
                    'per_page' => $lengthAwarePaginator->perPage(),
                ],
            ],
            'filters' => [
                'status' => $status !== '' ? $status : null,
                'scope' => $scope !== '' ? $scope : null,
                'service_id' => $serviceId > 0 ? $serviceId : null,
                'search' => $search,
                'unacknowledged' => $unacknowledged,
            ],
            'filterOptions' => [
                'statuses' => array_map(
                    static fn (MediaReplacementStatus $mediaReplacementStatus): array => ['value' => $mediaReplacementStatus->value, 'label' => $mediaReplacementStatus->label()],
                    MediaReplacementStatus::cases(),
                ),
                'scopes' => array_map(
                    static fn (MediaReplacementScope $mediaReplacementScope): array => ['value' => $mediaReplacementScope->value, 'label' => $mediaReplacementScope->label()],
                    MediaReplacementScope::cases(),
                ),
                'services' => ServiceConnection::query()
                    ->whereIn('id', MediaReplacementAttempt::query()->select('service_connection_id'))
                    ->orderBy('name')
                    ->get(['id', 'name', 'type'])
                    ->map(fn (ServiceConnection $serviceConnection): array => [
                        'id' => $serviceConnection->id,
                        'name' => $serviceConnection->name,
                        'type' => $serviceConnection->type->value,
                    ])
                    ->all(),
            ],
            'statusCounts' => $this->statusCounts(),
        ]);
    }

    /**
     * @return Builder<MediaReplacementAttempt>
     */
    private function buildBuilder(string $status, string $scope, int $serviceId, string $search, bool $unacknowledged): Builder
    {
        $builder = MediaReplacementAttempt::query()->latest();

        if ($status !== '') {
            $builder->where('status', $status);
        }

        if ($scope !== '') {
            $builder->where('scope', $scope);
        }

        if ($serviceId > 0) {
            $builder->where('service_connection_id', $serviceId);
        }

        if ($unacknowledged) {
            $builder->whereNull('acknowledged_at');
        }

        if ($search !== '') {
            // PostgreSQL JSON text extraction; LIKE metacharacters escaped so the
            // operator's text is matched literally.
            $like = sprintf('%%%s%%', addcslashes($search, '%_\\'));
            $builder->where(function (Builder $builder) use ($like): void {
                $builder->whereRaw("target->>'display_name' ILIKE ?", [$like])
                    ->orWhereRaw("candidate->>'title' ILIKE ?", [$like]);
            });
        }

        return $builder;
    }

    /**
     * Per-status totals for the count strip, independent of the active
     * filters (same contract as the Action Queue's statusCounts), plus the
     * open-attention number the badges use.
     *
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = MediaReplacementAttempt::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $out = [];
        foreach (MediaReplacementStatus::cases() as $mediaReplacementStatus) {
            $out[$mediaReplacementStatus->value] = (int) ($counts[$mediaReplacementStatus->value] ?? 0);
        }

        $out['attention_unacknowledged'] = MediaReplacementAttempt::unacknowledgedAttentionCount();

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MediaReplacementAttempt $mediaReplacementAttempt): array
    {
        $target = is_array($mediaReplacementAttempt->target) ? $mediaReplacementAttempt->target : [];
        $candidate = is_array($mediaReplacementAttempt->candidate) ? $mediaReplacementAttempt->candidate : [];
        $verification = is_array($mediaReplacementAttempt->verification) ? $mediaReplacementAttempt->verification : null;

        return [
            'id' => $mediaReplacementAttempt->id,
            'action_request_id' => $mediaReplacementAttempt->action_request_id,
            'action_request_status' => $mediaReplacementAttempt->actionRequest?->status->value,
            'status' => $mediaReplacementAttempt->status->value,
            'failure_reason' => $mediaReplacementAttempt->failure_reason,
            'scope' => $mediaReplacementAttempt->scope,
            'service_name' => $mediaReplacementAttempt->serviceConnection?->name,
            'service_type' => $mediaReplacementAttempt->serviceConnection?->type->value,
            'display_name' => $this->stringOrNull($target['display_name'] ?? null),
            'season_number' => is_int($target['season_number'] ?? null) ? $target['season_number'] : null,
            'episode_numbers' => array_values(array_filter(is_array($target['episode_numbers'] ?? null) ? $target['episode_numbers'] : [], is_int(...))),
            'candidate_title' => $this->stringOrNull($candidate['title'] ?? null),
            'candidate_release_group' => $this->stringOrNull($candidate['release_group'] ?? null),
            'candidate_quality' => $this->qualityName($candidate['quality'] ?? null),
            'required_languages' => $this->stringList($mediaReplacementAttempt->required_languages),
            'verification' => $verification === null ? null : [
                'subtitles_checked' => ($verification['subtitles_checked'] ?? null) === true,
                'found' => $this->stringList($verification['found'] ?? null),
                'missing' => $this->stringList($verification['missing'] ?? null),
            ],
            'monitoring_suspended' => $mediaReplacementAttempt->monitoring_suspended === true,
            'acknowledged_at' => $mediaReplacementAttempt->acknowledged_at?->toIso8601String(),
            'started_at' => $mediaReplacementAttempt->started_at?->toIso8601String(),
            'completed_at' => $mediaReplacementAttempt->completed_at?->toIso8601String(),
            'created_at' => $mediaReplacementAttempt->created_at?->toIso8601String(),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Quality arrives as a plain name from the inspector or as an arr
     * `{quality: {name}}` object from a raw release; render either.
     */
    private function qualityName(mixed $value): ?string
    {
        if (is_array($value)) {
            return $this->stringOrNull($value['name'] ?? ($value['quality']['name'] ?? null));
        }

        return $this->stringOrNull($value);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
