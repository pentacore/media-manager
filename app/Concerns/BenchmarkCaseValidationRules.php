<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Ai\Routing\DecisionActionKind;
use App\Enums\AiMode;
use App\Enums\AiTask;
use App\Enums\BenchmarkConversationMode;
use App\Enums\BenchmarkScoringType;
use App\Models\AiToolInvocation;
use App\Models\BenchmarkCaseAttachment;
use App\Services\AiUsage\Pricing\RefreshScope;
use App\Settings\DecisionAgentSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared by the benchmark case requests: field shapes in the rules, and the
 * cross-field checks (scoring type per task, tool arguments as JSON objects —
 * an empty list stands for `{}` — and canonical providers) in after().
 */
trait BenchmarkCaseValidationRules
{
    use ChatAttachmentValidationRules;
    use DecisionEventValidationRules;
    use VerifierCaseValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function benchmarkCaseRules(): array
    {
        return [
            'task' => ['required', 'string', Rule::in(array_map(static fn (AiTask $aiTask): string => $aiTask->value, array_filter(AiTask::cases(), static fn (AiTask $aiTask): bool => $aiTask->isBenchmarkable())))],
            'name' => ['required', 'string', 'max:160'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'input' => ['required', 'array'],
            'input.prompt' => [Rule::requiredIf(fn (): bool => $this->input('input.event') === null && $this->input('input.verifier') === null), 'nullable', 'string', 'max:65536'],
            'input.history' => ['nullable', 'array', 'max:50'],
            'input.history.*' => ['array'],
            'input.history.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'input.history.*.content' => ['required', 'string', 'max:20000'],
            'input.mode' => ['nullable', AiMode::validationRule()],
            'input.conversation' => ['nullable', BenchmarkConversationMode::validationRule()],
            'input.event_key' => ['nullable', 'string', Rule::in(DecisionAgentSettings::availableEventKeys())],
            'input.event' => ['nullable', 'array:service,event_type,payload_json'],
            ...$this->decisionEventRules('input.event.', false),
            'input.verifier' => ['nullable', 'array:action_type,action_label,title,description,details,verified,payload_json,rationale,source_service,event_type,event_payload_json'],
            ...$this->verifierCaseRules('input.verifier.', false),
            'input.action_kind' => ['nullable', DecisionActionKind::validationRule()],
            'input.providers' => [Rule::requiredIf(fn (): bool => $this->input('task') === AiTask::PriceUpdater->value), 'array'],
            'input.providers.*' => ['string'],
            'fixtures' => ['nullable', 'array', 'max:200'],
            'fixtures.*' => ['array'],
            'fixtures.*.tool' => ['required', 'string', 'max:120'],
            'fixtures.*.arguments' => ['nullable', 'array'],
            'fixtures.*.result' => ['nullable', 'string', sprintf('max:%d', AiToolInvocation::MAX_IO_BYTES + 20)],
            'scoring' => ['required', 'array'],
            'scoring.type' => ['required', BenchmarkScoringType::validationRule()],
            'scoring.expected' => ['nullable', 'array'],
            'scoring.expected.*' => ['array'],
            'scoring.expected.*.tool' => ['required_if:scoring.type,actions', 'string'],
            'scoring.expected.*.arguments' => ['nullable', 'array'],
            'scoring.expected.*.provider' => ['required_if:scoring.type,prices', 'string'],
            'scoring.expected.*.model' => ['required_if:scoring.type,prices', 'string'],
            'scoring.expected.*.input_per_mtok' => ['required_if:scoring.type,prices', 'numeric', 'min:0'],
            'scoring.expected.*.output_per_mtok' => ['required_if:scoring.type,prices', 'numeric', 'min:0'],
            'scoring.expected.*.cache_read_per_mtok' => ['nullable', 'numeric', 'min:0'],
            'scoring.expected.*.cache_write_per_mtok' => ['nullable', 'numeric', 'min:0'],
            'scoring.tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'scoring.expected_agrees' => ['nullable', 'required_if:scoring.type,verdict', 'boolean'],
            'scoring.rubric_note' => ['nullable', 'string', 'max:2000'],
            'scoring.pass_threshold' => ['nullable', 'integer', 'min:1', 'max:10'],
            'source_invocation_id' => ['nullable', 'string', 'max:120'],
            'source_prompt_tokens' => ['nullable', 'integer', 'min:0'],
            'source_completion_tokens' => ['nullable', 'integer', 'min:0'],
            'source_truncated' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['array'],
            'attachments.*.*' => ['file', $this->chatAttachmentFileRule()],
            'keep_attachment_ids' => ['nullable', 'array'],
            'keep_attachment_ids.*' => ['array'],
            'keep_attachment_ids.*.*' => ['integer'],
            'draft_attachment_ids' => ['nullable', 'array'],
            'draft_attachment_ids.*' => ['array'],
            'draft_attachment_ids.*.*' => ['integer'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $aiTask = AiTask::tryFrom((string) $this->input('task'));

                if ($aiTask instanceof AiTask && $aiTask->isBenchmarkable() && ! $validator->errors()->has('scoring.type') && $this->input('scoring.type') !== $aiTask->benchmarkScoringType()->value) {
                    $validator->errors()->add('scoring.type', sprintf('The %s task is scored with %s.', $aiTask->label(), $aiTask->benchmarkScoringType()->value));
                }
            },
            function (Validator $validator): void {
                if ($this->input('input.event') !== null && $this->input('task') !== AiTask::Decision->value) {
                    $validator->errors()->add('input.event', 'Only decision cases can carry a webhook event.');
                }
            },
            function (Validator $validator): void {
                if ($this->input('input.verifier') !== null && $this->input('task') !== AiTask::DecisionVerifier->value) {
                    $validator->errors()->add('input.verifier', 'Only decision verifier cases can carry a structured case file.');
                }
            },
            function (Validator $validator): void {
                if (is_array($this->input('input.verifier'))) {
                    $this->checkVerifierPayloadObjects($validator, 'input.verifier.');
                }
            },
            function (Validator $validator): void {
                $this->checkDecisionPayloadObject($validator, 'input.event.payload_json');
            },
            function (Validator $validator): void {
                foreach (['fixtures', 'scoring.expected'] as $listKey) {
                    $rows = $this->input($listKey);

                    foreach (is_array($rows) ? $rows : [] as $index => $row) {
                        $arguments = is_array($row) ? ($row['arguments'] ?? null) : null;

                        if (is_array($arguments) && $arguments !== [] && array_is_list($arguments)) {
                            $validator->errors()->add(sprintf('%s.%s.arguments', $listKey, $index), 'The arguments must be a JSON object.');
                        }
                    }
                }
            },
            function (Validator $validator): void {
                $providers = $this->input('input.providers');

                if (! is_array($providers) || $this->input('task') !== AiTask::PriceUpdater->value) {
                    return;
                }

                foreach ($providers as $index => $provider) {
                    if (! is_string($provider) || RefreshScope::canonicalProvider($provider) !== $provider) {
                        $validator->errors()->add(sprintf('input.providers.%s', $index), 'Use the canonical provider id.');
                    }
                }
            },
            function (Validator $validator): void {
                $isChat = $this->input('task') === AiTask::Chat->value;
                $history = $this->input('input.history');
                $history = is_array($history) ? $history : [];

                foreach ($this->attachmentTurnMaps() as $map => $turns) {
                    foreach (array_keys($turns) as $key) {
                        $key = (string) $key;

                        if (! $isChat) {
                            $validator->errors()->add($map, 'Only chat cases can carry attachments.');

                            continue;
                        }

                        $isUserTurn = ctype_digit($key) && is_array($history[(int) $key] ?? null) && ($history[(int) $key]['role'] ?? null) === 'user';

                        if ($key !== BenchmarkCaseAttachment::FINAL_TURN_KEY && ! $isUserTurn) {
                            $validator->errors()->add(sprintf('%s.%s', $map, $key), 'Attachments belong to the prompt or a user turn.');
                        }
                    }
                }
            },
            function (Validator $validator): void {
                $totals = [];

                foreach ($this->attachmentTurnMaps() as $turns) {
                    foreach ($turns as $key => $items) {
                        $totals[(string) $key] = ($totals[(string) $key] ?? 0) + (is_array($items) ? count($items) : 0);
                    }
                }

                foreach ($totals as $key => $total) {
                    if ($total > 3) {
                        $validator->errors()->add(sprintf('attachments.%s', $key), 'A turn can carry at most 3 attachments.');
                    }
                }
            },
            function (Validator $validator): void {
                if ($this->input('task') !== AiTask::Chat->value || $this->input('input.conversation') !== BenchmarkConversationMode::Scripted->value) {
                    return;
                }

                $history = $this->input('input.history');
                $userTurns = count(array_filter(is_array($history) ? $history : [], static fn (mixed $message): bool => is_array($message) && ($message['role'] ?? null) === 'user'));

                if ($userTurns + 1 > 10) {
                    $validator->errors()->add('input.conversation', 'A scripted case can have at most 10 user turns, including the prompt.');
                }
            },
        ];
    }

    /**
     * The three per-turn attachment maps of the request, by field name.
     *
     * @return array<string, array<array-key, mixed>>
     */
    private function attachmentTurnMaps(): array
    {
        $maps = [
            'attachments' => $this->allFiles()['attachments'] ?? [],
            'keep_attachment_ids' => $this->input('keep_attachment_ids'),
            'draft_attachment_ids' => $this->input('draft_attachment_ids'),
        ];

        return array_map(static fn (mixed $map): array => is_array($map) ? $map : [], $maps);
    }
}
