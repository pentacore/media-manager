<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\AiMode;
use App\Enums\AiTask;
use App\Enums\BenchmarkScoringType;
use App\Models\AiToolInvocation;
use App\Services\AiUsage\Pricing\RefreshScope;
use App\Settings\DecisionAgentSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared by the benchmark case requests: field shapes in the rules, and the
 * cross-field checks (scoring type per task, canonical providers) in after().
 */
trait BenchmarkCaseValidationRules
{
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
            'input.prompt' => ['required', 'string', 'max:65536'],
            'input.history' => ['nullable', 'array', 'max:50'],
            'input.history.*' => ['array'],
            'input.history.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'input.history.*.content' => ['required', 'string', 'max:20000'],
            'input.mode' => ['nullable', AiMode::validationRule()],
            'input.event_key' => ['nullable', 'string', Rule::in(DecisionAgentSettings::availableEventKeys())],
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
            'scoring.rubric_note' => ['nullable', 'string', 'max:2000'],
            'scoring.pass_threshold' => ['nullable', 'integer', 'min:1', 'max:10'],
            'source_invocation_id' => ['nullable', 'string', 'max:120'],
            'source_prompt_tokens' => ['nullable', 'integer', 'min:0'],
            'source_completion_tokens' => ['nullable', 'integer', 'min:0'],
            'source_truncated' => ['nullable', 'boolean'],
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
        ];
    }
}
