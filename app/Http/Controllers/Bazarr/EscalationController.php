<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class EscalationController extends BazarrController
{
    /**
     * @var list<SubtitleCaseStatus>
     */
    private const array FILTERABLE_STATUSES = [
        SubtitleCaseStatus::ReplacementEligible,
        SubtitleCaseStatus::AdvisorRunning,
        SubtitleCaseStatus::ReplacementRequested,
        SubtitleCaseStatus::NeedsReview,
        SubtitleCaseStatus::Resolved,
        SubtitleCaseStatus::Dismissed,
        SubtitleCaseStatus::Handled,
        SubtitleCaseStatus::Superseded,
    ];

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            ...$this->commonRules(),
            'status' => ['nullable', 'string', Rule::in($this->statusValues())],
        ]);
        $connectionProps = $this->connectionProps($request);
        $connection = $this->selectedConnection($connectionProps);
        $status = is_string($validated['status'] ?? null) ? $validated['status'] : '';
        $isAdmin = $request->user()?->isAdmin() === true;
        $lengthAwarePaginator = $this->query($connection, $status)
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Bazarr/Escalations', [
            ...$connectionProps,
            'cases' => [
                'data' => $lengthAwarePaginator->getCollection()
                    ->map(fn (SubtitleCase $subtitleCase): array => $this->serializeCase($subtitleCase))
                    ->values()
                    ->all(),
                'links' => $lengthAwarePaginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $lengthAwarePaginator->currentPage(),
                    'last_page' => $lengthAwarePaginator->lastPage(),
                    'total' => $lengthAwarePaginator->total(),
                    'per_page' => $lengthAwarePaginator->perPage(),
                ],
            ],
            'filters' => [
                'connection' => $connection?->id,
                'status' => $status,
            ],
            'filter_options' => [
                'connections' => $isAdmin ? $connectionProps['connections'] : [],
                'statuses' => $isAdmin
                    ? array_map(
                        static fn (SubtitleCaseStatus $subtitleCaseStatus): array => [
                            'value' => $subtitleCaseStatus->value,
                            'label' => str($subtitleCaseStatus->value)->replace('_', ' ')->title()->toString(),
                        ],
                        self::FILTERABLE_STATUSES,
                    )
                    : [],
            ],
            'can_filter' => $isAdmin,
            'can_investigate' => $request->user()?->isMember() === true,
        ]);
    }

    /**
     * @return Builder<SubtitleCase>
     */
    private function query(?ServiceConnection $serviceConnection, string $status): Builder
    {
        return SubtitleCase::query()
            ->with([
                'bazarrConnection:id,name',
                'serviceConnection:id,name,type',
                'downloadActionRequest:id,status',
                'replacementActionRequest:id,status',
            ])
            ->withCount([
                'attempts as probe_count' => static fn (Builder $builder): Builder => $builder
                    ->where('type', SubtitleCaseAttemptType::Probe),
            ])
            ->withMax([
                'attempts as last_probe_at' => static fn (Builder $builder): Builder => $builder
                    ->where('type', SubtitleCaseAttemptType::Probe),
            ], 'started_at')
            ->when(
                $serviceConnection instanceof ServiceConnection,
                static fn (Builder $builder): Builder => $builder->where('bazarr_connection_id', $serviceConnection->id),
                static fn (Builder $builder): Builder => $builder->whereRaw('1 = 0'),
            )
            ->whereIn('status', $status === '' ? $this->statusValues() : [$status])
            ->latest('observed_at')
            ->latest('id');
    }

    /**
     * @return array{
     *     id: int,
     *     display_name: string,
     *     media_type: string,
     *     scope: string,
     *     status: string,
     *     missing_languages: list<string>,
     *     probe_count: int,
     *     first_seen_at: string,
     *     last_probe_at: string|null,
     *     bazarr_connection: string,
     *     source_connection: string,
     *     download_action_status: string|null,
     *     replacement_action_status: string|null
     * }
     */
    private function serializeCase(SubtitleCase $subtitleCase): array
    {
        $displayName = $subtitleCase->evidence['display_name'] ?? null;
        $missingLanguages = $subtitleCase->evidence['missing_languages'] ?? null;
        $lastProbeAt = $subtitleCase->getAttribute('last_probe_at');

        return [
            'id' => $subtitleCase->id,
            'display_name' => is_string($displayName) && $displayName !== ''
                ? mb_substr($displayName, 0, 300)
                : sprintf('%s case #%d', ucfirst($subtitleCase->media_type), $subtitleCase->id),
            'media_type' => $subtitleCase->media_type,
            'scope' => $subtitleCase->scope,
            'status' => $subtitleCase->status->value,
            'missing_languages' => is_array($missingLanguages)
                ? array_values(array_slice(array_filter(
                    $missingLanguages,
                    static fn (mixed $language): bool => is_string($language) && $language !== '',
                ), 0, 20))
                : [],
            'probe_count' => (int) $subtitleCase->getAttribute('probe_count'),
            'first_seen_at' => $subtitleCase->observed_at->toIso8601String(),
            'last_probe_at' => $lastProbeAt instanceof CarbonInterface
                ? $lastProbeAt->toIso8601String()
                : (is_string($lastProbeAt) ? $lastProbeAt : null),
            'bazarr_connection' => $subtitleCase->bazarrConnection->name,
            'source_connection' => $subtitleCase->serviceConnection->name,
            'download_action_status' => $subtitleCase->downloadActionRequest?->status->value,
            'replacement_action_status' => $subtitleCase->replacementActionRequest?->status->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function statusValues(): array
    {
        return array_map(
            static fn (SubtitleCaseStatus $subtitleCaseStatus): string => $subtitleCaseStatus->value,
            self::FILTERABLE_STATUSES,
        );
    }
}
