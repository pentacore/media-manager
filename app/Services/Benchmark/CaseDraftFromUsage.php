<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Ai\Agents\MediaAgent;
use App\Ai\Benchmark\WriteToolDetector;
use App\Ai\Tools\PriceFetcher\UpsertModelPriceTool;
use App\Enums\AiMode;
use App\Enums\AiTask;
use App\Enums\AiUsageKind;
use App\Enums\BenchmarkScoringType;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\ChatAttachment;
use App\Services\AiUsage\Pricing\RefreshScope;
use App\Services\Chat\ChatAttachmentStore;
use App\Settings\AiSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Throwable;

/**
 * Pre-fills a benchmark case from a recorded AI usage row and the tool calls
 * it made. Capture is an admin convenience: the draft is edited before saving.
 *
 * @phpstan-type MessageRow object{role: string, content: string, attachments: string|null, meta: string|null, created_at: string}
 */
final readonly class CaseDraftFromUsage
{
    private const string ATTACHMENTS_MARKER = "\n\nAttachments: ";

    private const int NAME_PROMPT_CHARS = 60;

    public function __construct(private AiSettings $aiSettings, private ChatAttachmentStore $chatAttachmentStore, private DecisionCaseCapture $decisionCaseCapture, private VerifierCaseCapture $verifierCaseCapture) {}

    /**
     * @return array{draft: array<string, mixed>, warnings: list<string>}
     *
     * @throws CaseDraftRefused
     */
    public function draft(AiUsageRecord $aiUsageRecord): array
    {
        $prompt = $aiUsageRecord->prompt_text;

        $blocker = self::captureBlocker($aiUsageRecord);

        throw_if($blocker !== null, CaseDraftRefused::class, $blocker);

        $hadAttachments = str_contains((string) $prompt, self::ATTACHMENTS_MARKER);
        $prompt = $this->promptWithoutAttachmentList((string) $prompt);

        $aiTask = $this->taskFor($aiUsageRecord);
        $warnings = [];

        if (str_ends_with($prompt, '…')) {
            $warnings[] = 'The recorded prompt was cut short; complete it before saving.';
        }

        $invocations = AiToolInvocation::query()
            ->where('invocation_id', $aiUsageRecord->invocation_id)
            ->orderBy('id')
            ->get();

        if ($invocations->contains(fn (AiToolInvocation $invocation): bool => $invocation->io_truncated)) {
            $warnings[] = 'Some tool calls were recorded truncated; their fixtures are incomplete.';
        }

        if ($invocations->contains(fn (AiToolInvocation $invocation): bool => $invocation->arguments === null || $invocation->result === null)) {
            $warnings[] = 'Some tool calls were recorded without arguments or results; fill the fixtures in by hand.';
        }

        $fixtures = [];

        foreach ($invocations as $invocation) {
            $call = LegacyStuckImportCall::current([
                'tool' => $invocation->tool_name ?? class_basename($invocation->tool_class),
                'arguments' => $invocation->arguments ?? [],
                'result' => $invocation->result ?? '',
            ]);

            $fixtures[] = [
                ...$call,
                'writes' => $this->writes($invocation->tool_class),
                'tool_class' => $invocation->tool_class,
            ];
        }

        $input = ['prompt' => $prompt];
        $draftAttachments = [];

        if ($aiTask === AiTask::Chat) {
            $chat = $this->chatTurns($aiUsageRecord, $prompt, $hadAttachments);

            $input['history'] = $chat['conversation']['history'];
            $input['mode'] = ($chat['replyMode'] ?? $this->aiSettings->mode())->value;
            $input['conversation'] = 'context';
            $draftAttachments = $chat['attachments'];
            array_push($warnings, ...$chat['warnings']);
        }

        $sourceTruncated = $warnings !== [];
        $expectedAgrees = null;
        $name = sprintf('%s: %s', $aiTask->label(), Str::limit(Str::squish($prompt), self::NAME_PROMPT_CHARS));

        if ($aiTask === AiTask::Decision) {
            $decisionDraft = $this->decisionCaseCapture->capture($aiUsageRecord, $prompt);

            $input = $decisionDraft->input;
            array_push($warnings, ...$decisionDraft->warnings);
            $sourceTruncated = $sourceTruncated || $decisionDraft->truncated;

            if ($decisionDraft->eventLabel !== null) {
                $name = sprintf('%s: %s', $aiTask->label(), $decisionDraft->eventLabel);
            }
        }

        if ($aiTask === AiTask::DecisionVerifier) {
            $verifierDraft = $this->verifierCaseCapture->fromUsage($aiUsageRecord, $prompt);

            $input = $verifierDraft->input;
            $expectedAgrees = $verifierDraft->expectedAgrees;
            array_push($warnings, ...$verifierDraft->warnings);

            if ($verifierDraft->name !== null) {
                $name = $verifierDraft->name;
            }
        }

        if ($aiTask === AiTask::PriceUpdater) {
            $input['providers'] = $this->providersIn($prompt);

            if ($input['providers'] === []) {
                $warnings[] = 'No provider was recognised in the prompt; fill in the providers.';
            }
        }

        return [
            'draft' => [
                'task' => $aiTask->value,
                'name' => $name,
                'tags' => [],
                'notes' => null,
                'input' => $input,
                'draft_attachments' => $draftAttachments,
                'fixtures' => array_map(
                    static fn (array $fixture): array => ['tool' => $fixture['tool'], 'arguments' => $fixture['arguments'], 'result' => $fixture['result']],
                    $fixtures,
                ),
                'scoring' => $this->scoring($aiTask, $fixtures, $expectedAgrees),
                'source_invocation_id' => $aiUsageRecord->invocation_id,
                'source_prompt_tokens' => $aiUsageRecord->prompt_tokens,
                'source_completion_tokens' => $aiUsageRecord->completion_tokens,
                'source_truncated' => $sourceTruncated,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * The ids of the chat attachments of the captured chat turn and the
     * user turns before it, the only ones a case saved from this usage row
     * may copy in (a file deleted since is skipped at save, not refused).
     * Empty when the row cannot be captured or is not a chat turn.
     *
     * @return list<int>
     */
    public function draftAttachmentIds(AiUsageRecord $aiUsageRecord): array
    {
        if (self::captureBlocker($aiUsageRecord) !== null) {
            return [];
        }

        try {
            $aiTask = $this->taskFor($aiUsageRecord);
        } catch (CaseDraftRefused) {
            return [];
        }

        if ($aiTask !== AiTask::Chat) {
            return [];
        }

        $conversation = $this->conversation($aiUsageRecord, $this->promptWithoutAttachmentList((string) $aiUsageRecord->prompt_text));
        $ids = [];

        foreach ([...$conversation['historyRows'], ...array_filter([$conversation['captured']])] as $row) {
            if ($row->role === 'user') {
                array_push($ids, ...array_column($this->storedAttachments($row), 'id'));
            }
        }

        return $ids;
    }

    private function promptWithoutAttachmentList(string $prompt): string
    {
        return str_contains($prompt, self::ATTACHMENTS_MARKER)
            ? substr($prompt, 0, (int) strrpos($prompt, self::ATTACHMENTS_MARKER))
            : $prompt;
    }

    /**
     * The conversation before a captured chat turn, the mode its reply ran
     * in, and the still-stored attachments of every kept user turn and the
     * captured one, with warnings for the ones left out.
     *
     * @return array{conversation: array{history: list<array{role: string, content: string}>, historyRows: list<MessageRow>, captured: MessageRow|null, reply: MessageRow|null}, replyMode: AiMode|null, attachments: list<array{chat_attachment_id: int, turn: int|null, name: string, mime_type: string, size: int, url: string}>, warnings: list<string>}
     */
    private function chatTurns(AiUsageRecord $aiUsageRecord, string $prompt, bool $hadAttachments): array
    {
        $conversation = $this->conversation($aiUsageRecord, $prompt);
        $replyMode = AiMode::tryFrom((string) (json_decode((string) ($conversation['reply']->meta ?? ''), true)['mode'] ?? ''));
        $warnings = [];
        $attachments = [];
        $turns = [];

        foreach ($conversation['historyRows'] as $index => $row) {
            if ($row->role === 'user') {
                $turns[] = [$index, $row];
            }
        }

        if ($conversation['captured'] !== null) {
            $turns[] = [null, $conversation['captured']];
        }

        $missing = 0;
        $capturedFound = 0;

        foreach ($turns as [$turn, $row]) {
            $found = $this->attachmentsOf($aiUsageRecord, $row, $turn, $missing);
            array_push($attachments, ...$found);

            if ($turn === null) {
                $capturedFound = count($found);
            }
        }

        if ($missing > 0) {
            $warnings[] = 'Some attachments of this conversation are no longer stored and were left out.';
        }

        if ($hadAttachments && $conversation['captured'] === null) {
            $warnings[] = 'The captured message could not be located, so its attachments were left out; attach them by hand.';
        }

        if ($hadAttachments && $conversation['captured'] !== null && $capturedFound === 0 && $missing === 0) {
            $warnings[] = 'The captured turn listed attachments, but none could be found.';
        }

        return ['conversation' => $conversation, 'replyMode' => $replyMode, 'attachments' => $attachments, 'warnings' => $warnings];
    }

    /**
     * Why a usage row cannot become a case, or null when its origin, kind and
     * recorded prompt allow it (the agent is checked when drafting).
     */
    public static function captureBlocker(AiUsageRecord $aiUsageRecord): ?string
    {
        return match (true) {
            $aiUsageRecord->is_benchmark => "Benchmark runs can't be captured as cases.",
            $aiUsageRecord->kind !== AiUsageKind::Text => 'Only text invocations can become benchmark cases.',
            blank($aiUsageRecord->prompt_text) => 'This invocation has no recorded prompt.',
            str_contains((string) $aiUsageRecord->prompt_text, self::ATTACHMENTS_MARKER) && $aiUsageRecord->agent_class !== MediaAgent::class => 'Only chat invocations with attachments can become benchmark cases.',
            default => null,
        };
    }

    private function taskFor(AiUsageRecord $aiUsageRecord): AiTask
    {
        $agentClass = $aiUsageRecord->agent_class;

        throw_if($agentClass === null || ! class_exists($agentClass) || ! method_exists($agentClass, 'aiTask'), CaseDraftRefused::class, 'This invocation was not made by a benchmarkable agent.');

        $aiTask = resolve($agentClass)->aiTask();

        throw_if(! $aiTask instanceof AiTask || ! $aiTask->isBenchmarkable(), CaseDraftRefused::class, 'This invocation was not made by a benchmarkable agent.');

        return $aiTask;
    }

    /**
     * The conversation turns before the captured one. The SDK stores a turn's
     * messages before its usage row and timestamps are second-precise, so
     * every message up to the end of the record's second is read and the
     * captured turn (the last user message that is the recorded prompt, and
     * everything after it) is cut off. When no user message equals the
     * recorded prompt (it was truncated), the captured turn cannot be located,
     * so only messages from strictly before the record's second are kept.
     *
     * @return array{history: list<array{role: string, content: string}>, historyRows: list<MessageRow>, captured: MessageRow|null, reply: MessageRow|null}
     */
    private function conversation(AiUsageRecord $aiUsageRecord, string $prompt): array
    {
        $none = ['history' => [], 'historyRows' => [], 'captured' => null, 'reply' => null];

        if ($aiUsageRecord->conversation_id === null || $aiUsageRecord->created_at === null) {
            return $none;
        }

        $secondStart = $aiUsageRecord->created_at->startOfSecond();

        /** @var list<MessageRow> $messages */
        $messages = DB::table('agent_conversation_messages')
            ->where('conversation_id', $aiUsageRecord->conversation_id)
            ->whereIn('role', ['user', 'assistant'])
            ->where('created_at', '<', $secondStart->addSecond())
            ->oldest()
            ->orderBy('id')
            ->get(['role', 'content', 'attachments', 'meta', 'created_at'])
            ->all();

        $captured = null;
        $reply = null;
        $kept = null;

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if ($messages[$index]->role === 'user' && $messages[$index]->content === $prompt) {
                $kept = array_slice($messages, 0, $index);
                $captured = $messages[$index];

                foreach (array_slice($messages, $index + 1) as $later) {
                    if ($later->role === 'assistant') {
                        $reply = $later;

                        break;
                    }
                }

                break;
            }
        }

        $kept ??= array_values(array_filter($messages, static fn (object $message): bool => CarbonImmutable::parse((string) $message->created_at, $secondStart->getTimezone())->lessThan($secondStart)));

        return [
            'history' => array_map(static fn (object $message): array => ['role' => (string) $message->role, 'content' => (string) $message->content], $kept),
            'historyRows' => $kept,
            'captured' => $captured,
            'reply' => $reply,
        ];
    }

    /**
     * The `attachments` JSON entries of one stored message.
     *
     * @param  MessageRow  $message
     * @return list<array<string, mixed>>
     */
    private function storedEntries(object $message): array
    {
        $stored = json_decode((string) $message->attachments, true);

        return is_array($stored) ? array_values(array_filter($stored, is_array(...))) : [];
    }

    /**
     * The chat attachment rows one stored message refers to, file or not.
     *
     * @param  MessageRow  $message
     * @return list<array{id: int, name: string, mime: string, url: string}>
     */
    private function storedAttachments(object $message): array
    {
        $stored = $this->storedEntries($message);

        return $stored === [] ? [] : $this->chatAttachmentStore->forMessage($stored);
    }

    /**
     * The chat attachments of one stored message that still have a file,
     * each with its admin preview through the captured usage row.
     *
     * @param  MessageRow  $message
     * @return list<array{chat_attachment_id: int, turn: int|null, name: string, mime_type: string, size: int, url: string}>
     */
    private function attachmentsOf(AiUsageRecord $aiUsageRecord, object $message, ?int $turn, int &$missing): array
    {
        $stored = $this->storedEntries($message);

        if ($stored === []) {
            return [];
        }

        $mapped = $this->storedAttachments($message);
        $missing += max(0, count($stored) - count($mapped));

        $rows = ChatAttachment::query()->whereIn('id', array_column($mapped, 'id'))->orderBy('id')->get();
        $drafts = [];

        foreach ($mapped as $entry) {
            $row = $rows->firstWhere('id', $entry['id']);

            if ($row === null || ! Storage::disk($row->disk)->exists($row->path)) {
                $missing++;

                continue;
            }

            $drafts[] = [
                'chat_attachment_id' => $row->id,
                'turn' => $turn,
                'name' => $row->original_name,
                'mime_type' => $row->mime_type,
                'size' => $row->size,
                'url' => route('admin.benchmarks.cases.draft-attachments.show', [$aiUsageRecord, $row]),
            ];
        }

        return $drafts;
    }

    /**
     * @return list<string>
     */
    private function providersIn(string $prompt): array
    {
        preg_match_all('/[a-z0-9]+/', strtolower($prompt), $matches);

        $providers = [];

        foreach ($matches[0] as $word) {
            $canonical = RefreshScope::canonicalProvider($word);

            if ($canonical !== null && ! in_array($canonical, $providers, true)) {
                $providers[] = $canonical;
            }
        }

        return $providers;
    }

    private function writes(string $toolClass): bool
    {
        if (! str_starts_with($toolClass, 'App\\') || ! class_exists($toolClass) || ! is_subclass_of($toolClass, Tool::class)) {
            return false;
        }

        try {
            return WriteToolDetector::writes(resolve($toolClass));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<array{tool: string, arguments: array<string, mixed>, result: string, writes: bool, tool_class: string}>  $fixtures
     * @return array<string, mixed>
     */
    private function scoring(AiTask $aiTask, array $fixtures, ?bool $expectedAgrees): array
    {
        return match ($aiTask->benchmarkScoringType()) {
            BenchmarkScoringType::Actions => [
                'type' => BenchmarkScoringType::Actions->value,
                'expected' => array_values(array_map(
                    static fn (array $fixture): array => ['tool' => $fixture['tool'], 'arguments' => $fixture['arguments']],
                    array_filter($fixtures, static fn (array $fixture): bool => $fixture['writes']),
                )),
            ],
            BenchmarkScoringType::Prices => [
                'type' => BenchmarkScoringType::Prices->value,
                'tolerance_percent' => 1,
                'expected' => array_values(array_map(
                    fn (array $fixture): array => $this->priceExpectation($fixture['arguments']),
                    array_filter($fixtures, static fn (array $fixture): bool => $fixture['tool_class'] === UpsertModelPriceTool::class),
                )),
            ],
            BenchmarkScoringType::Judge => [
                'type' => BenchmarkScoringType::Judge->value,
                'rubric_note' => null,
                'pass_threshold' => 7,
            ],
            BenchmarkScoringType::Verdict => ['type' => BenchmarkScoringType::Verdict->value, 'expected_agrees' => $expectedAgrees],
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function priceExpectation(array $arguments): array
    {
        $provider = (string) ($arguments['provider'] ?? '');

        return [
            'provider' => RefreshScope::canonicalProvider($provider) ?? $provider,
            'model' => (string) ($arguments['model'] ?? ''),
            'input_per_mtok' => $arguments['input_per_mtok'] ?? null,
            'output_per_mtok' => $arguments['output_per_mtok'] ?? null,
            'cache_read_per_mtok' => $arguments['cache_read_per_mtok'] ?? null,
            'cache_write_per_mtok' => $arguments['cache_write_per_mtok'] ?? null,
        ];
    }
}
