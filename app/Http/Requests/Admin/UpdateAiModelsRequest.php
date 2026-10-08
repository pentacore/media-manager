<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\AiModelSelectionValidationRules;
use App\Enums\AiTask;
use App\Settings\DecisionAgentSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/**
 * `tasks` and `event_overrides` are overridden because typefinder cannot render
 * the nested per-task tier lists.
 */
#[TypefinderOverrides([
    'tasks' => 'Record<string, { tiers: Array<{ provider: string | null; model: string | null; reasoning: string | null; min_pool_percent: number | null; min_pool_tokens: number | null }> }>',
    'event_overrides' => 'Array<{ event_key: string; tiers: Array<{ provider: string | null; model: string | null; reasoning: string | null; min_pool_percent: number | null; min_pool_tokens: number | null }> }>',
])]
class UpdateAiModelsRequest extends FormRequest
{
    use AiModelSelectionValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'tasks' => ['required', 'array:'.implode(',', self::defaultTasks())],
            'failover' => ['present', 'array:provider,model'],
            ...$this->selectionRules('failover', withReasoning: false),
            'event_overrides' => ['present', 'array'],
            'event_overrides.*' => ['array:event_key,tiers'],
            'event_overrides.*.event_key' => ['required', 'string', 'distinct', Rule::in(DecisionAgentSettings::availableEventKeys())],
            'event_overrides.*.tiers' => ['required', 'array', 'list', 'min:1'],
            ...$this->tierRules('event_overrides.*.tiers.*'),
        ];

        foreach (self::defaultTasks() as $task) {
            $rules[sprintf('tasks.%s', $task)] = ['required', 'array:tiers'];
            $rules[sprintf('tasks.%s.tiers', $task)] = ['required', 'array', 'list', 'min:1'];
            $rules = [...$rules, ...$this->tierRules(sprintf('tasks.%s.tiers.*', $task))];
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (self::defaultTasks() as $task) {
                $this->validateTierList($validator, sprintf('tasks.%s.tiers', $task), $this->input(sprintf('tasks.%s.tiers', $task)), allowAuto: $task === AiTask::Title->value);
            }

            $this->validatePricedSelection($validator, 'failover', $this->input('failover.provider'), $this->input('failover.model'));

            foreach ((array) $this->input('event_overrides', []) as $index => $override) {
                if (is_array($override)) {
                    $this->validateTierList($validator, sprintf('event_overrides.%d.tiers', $index), $override['tiers'] ?? null);
                }
            }
        }];
    }

    /**
     * Every task with a default row on this page; failover has its own field.
     *
     * @return list<string>
     */
    public static function defaultTasks(): array
    {
        $tasks = [];

        foreach (AiTask::cases() as $aiTask) {
            if ($aiTask !== AiTask::Failover) {
                $tasks[] = $aiTask->value;
            }
        }

        return $tasks;
    }
}
