<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Benchmark\WriteToolDetector;
use App\Enums\AiTask;
use App\Enums\BenchmarkConversationMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBenchmarkCaseRequest;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use App\Services\Audit\AuditLogger;
use App\Services\Benchmark\CaptureSource;
use App\Services\Benchmark\CaseAttachmentSync;
use App\Services\Benchmark\CaseDraftFromUsage;
use App\Services\Benchmark\CaseDraftRefused;
use App\Services\Benchmark\DefaultRubrics;
use App\Settings\DecisionAgentSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\ToolNameResolver;

class BenchmarkCaseController extends Controller
{
    private const int PER_PAGE = 25;

    /**
     * Agents whose tools can appear as expected actions.
     *
     * @var list<class-string>
     */
    private const array TOOL_AGENTS = [MediaAgent::class, DecisionAgent::class, PriceFetcherAgent::class];

    public function index(Request $request): Response
    {
        $task = AiTask::tryFrom($request->string('task')->value());
        $tag = $request->string('tag')->trim()->value();

        $lengthAwarePaginator = $this->casesQuery($task, $tag)->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Admin/Benchmarks/Cases/Index', [
            'cases' => [
                'data' => $lengthAwarePaginator->getCollection()->map(static fn (BenchmarkCase $benchmarkCase): array => [
                    'id' => $benchmarkCase->id,
                    'task' => $benchmarkCase->task->value,
                    'task_label' => $benchmarkCase->task->label(),
                    'name' => $benchmarkCase->name,
                    'tags' => $benchmarkCase->tags,
                    'fixture_count' => count($benchmarkCase->fixtures),
                    'scoring_type' => $benchmarkCase->scoring['type'] ?? null,
                    'source_invocation_id' => $benchmarkCase->source_invocation_id,
                    'attachment_count' => $benchmarkCase->attachments_count ?? 0,
                    'scripted' => $benchmarkCase->isScripted(),
                    'updated_at' => $benchmarkCase->updated_at?->toIso8601String(),
                ])->all(),
                'links' => $lengthAwarePaginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $lengthAwarePaginator->currentPage(),
                    'last_page' => $lengthAwarePaginator->lastPage(),
                    'total' => $lengthAwarePaginator->total(),
                    'per_page' => $lengthAwarePaginator->perPage(),
                ],
            ],
            'tasks' => $this->taskOptions(),
            'filters' => ['task' => $task?->value, 'tag' => $tag === '' ? null : $tag],
        ]);
    }

    public function create(Request $request, CaseDraftFromUsage $caseDraftFromUsage, CaptureSource $captureSource): Response|RedirectResponse
    {
        $draft = null;
        $warnings = [];

        $aiUsageRecord = null;

        if ($request->filled('from_usage')) {
            $aiUsageRecord = AiUsageRecord::query()->findOrFail($request->integer('from_usage'));
        } elseif ($request->filled('from_invocation')) {
            $aiUsageRecord = $captureSource->forInvocation($request->string('from_invocation')->toString());

            if (! $aiUsageRecord instanceof AiUsageRecord) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __('This AI Usage record was not found.')]);

                return back();
            }
        } elseif ($request->filled('from_chat_message')) {
            $aiUsageRecord = $captureSource->forChatMessage($request->string('from_chat_message')->toString());

            if (! $aiUsageRecord instanceof AiUsageRecord) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __("This reply can't be traced to its AI Usage record.")]);

                return back();
            }
        }

        if ($aiUsageRecord instanceof AiUsageRecord) {
            try {
                ['draft' => $draft, 'warnings' => $warnings] = $caseDraftFromUsage->draft($aiUsageRecord);
            } catch (CaseDraftRefused $caseDraftRefused) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __($caseDraftRefused->getMessage())]);

                return back();
            }
        }

        return $this->form(null, $draft, $warnings);
    }

    public function store(StoreBenchmarkCaseRequest $storeBenchmarkCaseRequest, AuditLogger $auditLogger, CaseAttachmentSync $caseAttachmentSync): RedirectResponse
    {
        $validated = $storeBenchmarkCaseRequest->validated();

        [$benchmarkCase, $skipped] = DB::transaction(function () use ($validated, $caseAttachmentSync): array {
            $benchmarkCase = BenchmarkCase::create($this->attributes($validated));

            return [$benchmarkCase, $caseAttachmentSync->sync($benchmarkCase, null, $validated['draft_attachment_ids'] ?? [], $validated['attachments'] ?? [])];
        });

        $auditLogger->record(
            'benchmark_case.created',
            $benchmarkCase,
            sprintf('Created benchmark case "%s".', $benchmarkCase->name),
            context: ['task' => $benchmarkCase->task->value],
        );

        Inertia::flash('toast', $this->saveToast('created', $skipped));

        return to_route('admin.benchmarks.cases.index');
    }

    public function edit(BenchmarkCase $benchmarkCase): Response
    {
        return $this->form($benchmarkCase, null, []);
    }

    public function update(StoreBenchmarkCaseRequest $storeBenchmarkCaseRequest, BenchmarkCase $benchmarkCase, AuditLogger $auditLogger, CaseAttachmentSync $caseAttachmentSync): RedirectResponse
    {
        $validated = $storeBenchmarkCaseRequest->validated();

        $skipped = DB::transaction(function () use ($validated, $benchmarkCase, $caseAttachmentSync): int {
            $benchmarkCase->update($this->attributes($validated));

            return $caseAttachmentSync->sync(
                $benchmarkCase,
                array_key_exists('keep_attachment_ids', $validated) ? ($validated['keep_attachment_ids'] ?? []) : null,
                $validated['draft_attachment_ids'] ?? [],
                $validated['attachments'] ?? [],
            );
        });

        $auditLogger->record(
            'benchmark_case.updated',
            $benchmarkCase,
            sprintf('Updated benchmark case "%s".', $benchmarkCase->name),
            context: ['task' => $benchmarkCase->task->value],
        );

        Inertia::flash('toast', $this->saveToast('updated', $skipped));

        return to_route('admin.benchmarks.cases.index');
    }

    public function destroy(BenchmarkCase $benchmarkCase, AuditLogger $auditLogger): RedirectResponse
    {
        $benchmarkCase->delete();

        $auditLogger->record(
            'benchmark_case.deleted',
            $benchmarkCase,
            sprintf('Deleted benchmark case "%s".', $benchmarkCase->name),
            context: ['task' => $benchmarkCase->task->value],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Benchmark case deleted.')]);

        return to_route('admin.benchmarks.cases.index');
    }

    /**
     * @return array{type: string, message: string}
     */
    private function saveToast(string $action, int $skipped): array
    {
        if ($skipped === 0) {
            return ['type' => 'success', 'message' => __('Benchmark case :action.', ['action' => $action])];
        }

        return ['type' => 'info', 'message' => __('Benchmark case :action, but :count :attachments could not be copied because the file is no longer stored.', [
            'action' => $action,
            'count' => $skipped,
            'attachments' => Str::plural('attachment', $skipped),
        ])];
    }

    /**
     * @return Builder<BenchmarkCase>
     */
    private function casesQuery(?AiTask $task, string $tag): Builder
    {
        return BenchmarkCase::query()
            ->withCount('attachments')
            ->when($task instanceof AiTask, static fn (Builder $builder): Builder => $builder->where('task', $task))
            ->when($tag !== '', static fn (Builder $builder): Builder => $builder->whereJsonContains('tags', $tag))
            ->latest('updated_at')
            ->latest('id');
    }

    /**
     * @param  array<string, mixed>|null  $draft
     * @param  list<string>  $warnings
     */
    private function form(?BenchmarkCase $benchmarkCase, ?array $draft, array $warnings): Response
    {
        $benchmarkCase?->loadMissing('attachments');

        return Inertia::render('Admin/Benchmarks/Cases/Form', [
            'case' => $benchmarkCase instanceof BenchmarkCase ? [
                'id' => $benchmarkCase->id,
                'task' => $benchmarkCase->task->value,
                'name' => $benchmarkCase->name,
                'tags' => $benchmarkCase->tags,
                'notes' => $benchmarkCase->notes,
                'input' => $benchmarkCase->input,
                'fixtures' => $benchmarkCase->fixtures,
                'scoring' => $benchmarkCase->scoring,
                'source_invocation_id' => $benchmarkCase->source_invocation_id,
                'source_prompt_tokens' => $benchmarkCase->source_prompt_tokens,
                'source_completion_tokens' => $benchmarkCase->source_completion_tokens,
                'source_truncated' => $benchmarkCase->source_truncated,
                'attachments' => $benchmarkCase->attachments->sortBy('id')->values()->map(static fn (BenchmarkCaseAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'turn' => $attachment->turn,
                    'name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size' => $attachment->size,
                ])->all(),
            ] : null,
            'draft' => $draft,
            'draftWarnings' => $warnings,
            'tasks' => $this->taskOptions(),
            'eventKeys' => DecisionAgentSettings::availableEventKeys(),
            'writeTools' => $this->writeToolNames(),
            'defaultRubrics' => $this->defaultRubrics(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $aiTask = AiTask::from($validated['task']);
        $input = ['prompt' => $validated['input']['prompt']];

        if ($aiTask === AiTask::Chat) {
            $input['history'] = array_map(static fn (array $turn): array => ['role' => $turn['role'], 'content' => $turn['content']], $validated['input']['history'] ?? []);
            $input['mode'] = $validated['input']['mode'] ?? null;
            $input['conversation'] = $validated['input']['conversation'] ?? BenchmarkConversationMode::Context->value;
        }

        if ($aiTask === AiTask::Decision) {
            $input['event_key'] = $validated['input']['event_key'] ?? null;
        }

        if ($aiTask === AiTask::PriceUpdater) {
            $input['providers'] = $validated['input']['providers'];
        }

        $scoring = $validated['scoring'];

        return [
            'task' => $aiTask,
            'name' => $validated['name'],
            'tags' => $validated['tags'] ?? [],
            'notes' => $validated['notes'] ?? null,
            'input' => $input,
            'fixtures' => array_map(static fn (array $fixture): array => [
                'tool' => $fixture['tool'],
                'arguments' => $fixture['arguments'] ?? [],
                'result' => $fixture['result'] ?? '',
            ], $validated['fixtures'] ?? []),
            'scoring' => match ($scoring['type']) {
                'actions' => [
                    'type' => 'actions',
                    'expected' => array_map(static fn (array $action): array => [
                        'tool' => $action['tool'],
                        'arguments' => $action['arguments'] ?? [],
                    ], $scoring['expected'] ?? []),
                ],
                'prices' => [
                    'type' => 'prices',
                    'tolerance_percent' => $scoring['tolerance_percent'] ?? 1,
                    'expected' => array_map(static fn (array $price): array => [
                        'provider' => $price['provider'],
                        'model' => $price['model'],
                        'input_per_mtok' => $price['input_per_mtok'],
                        'output_per_mtok' => $price['output_per_mtok'],
                        'cache_read_per_mtok' => $price['cache_read_per_mtok'] ?? null,
                        'cache_write_per_mtok' => $price['cache_write_per_mtok'] ?? null,
                    ], $scoring['expected'] ?? []),
                ],
                default => [
                    'type' => 'judge',
                    'rubric_note' => $scoring['rubric_note'] ?? null,
                    'pass_threshold' => $scoring['pass_threshold'] ?? 7,
                ],
            },
            'source_invocation_id' => $validated['source_invocation_id'] ?? null,
            'source_prompt_tokens' => $validated['source_prompt_tokens'] ?? null,
            'source_completion_tokens' => $validated['source_completion_tokens'] ?? null,
            'source_truncated' => (bool) ($validated['source_truncated'] ?? false),
        ];
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function taskOptions(): array
    {
        return array_values(array_map(
            static fn (AiTask $aiTask): array => ['name' => $aiTask->label(), 'value' => $aiTask->value],
            array_filter(AiTask::cases(), static fn (AiTask $aiTask): bool => $aiTask->isBenchmarkable()),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function defaultRubrics(): array
    {
        $rubrics = [];

        foreach (AiTask::cases() as $aiTask) {
            if ($aiTask->isBenchmarkable() && $aiTask->benchmarkScoringType()->value === 'judge') {
                $rubrics[$aiTask->value] = DefaultRubrics::for($aiTask);
            }
        }

        return $rubrics;
    }

    /**
     * @return list<string>
     */
    private function writeToolNames(): array
    {
        $names = [];

        foreach (self::TOOL_AGENTS as $agentClass) {
            foreach (resolve($agentClass)->tools() as $tool) {
                if ($tool instanceof Tool && WriteToolDetector::writes($tool)) {
                    $names[] = ToolNameResolver::resolve($tool);
                }
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
