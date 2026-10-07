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
            'event_overrides.*.event_key' => ['required', 'string', 'distinct', Rule::in(DecisionAgentSettings::availableEventKeys())],
            ...$this->selectionRules('event_overrides.*'),
        ];

        foreach (self::defaultTasks() as $task) {
            $rules[sprintf('tasks.%s', $task)] = ['required', 'array:provider,model,reasoning'];
            $rules = [...$rules, ...$this->selectionRules(sprintf('tasks.%s', $task))];
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
                $selection = $this->input(sprintf('tasks.%s', $task));

                if (! is_array($selection)) {
                    continue;
                }

                $this->validatePricedSelection($validator, sprintf('tasks.%s', $task), $selection['provider'] ?? null, $selection['model'] ?? null, allowAuto: $task === AiTask::Title->value);
            }

            $this->validatePricedSelection($validator, 'failover', $this->input('failover.provider'), $this->input('failover.model'));

            foreach ((array) $this->input('event_overrides', []) as $index => $override) {
                if (! is_array($override)) {
                    continue;
                }

                $this->validatePricedSelection($validator, sprintf('event_overrides.%d', $index), $override['provider'] ?? null, $override['model'] ?? null);
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
        return array_values(array_map(
            static fn (AiTask $aiTask): string => $aiTask->value,
            array_filter(AiTask::cases(), static fn (AiTask $aiTask): bool => $aiTask !== AiTask::Failover),
        ));
    }
}
