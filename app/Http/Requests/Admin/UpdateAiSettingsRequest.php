<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AiMode;
use App\Enums\AiReasoningLevel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Override;

class UpdateAiSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', AiMode::validationRule()],
            'model' => ['required', 'string', 'max:100'],
            'title_model' => ['required', 'string', 'max:100'],
            'soft_budget_usd' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'hard_budget_usd' => ['nullable', 'numeric', 'min:0', 'max:100000', 'gte:soft_budget_usd'],
            'advisor_reasoning_level' => ['required', AiReasoningLevel::validationRule()],
            'failover_provider' => ['nullable', 'string', 'in:anthropic,openai,gemini,groq,mistral'],
        ];
    }

    /**
     * Normalize the "None" failover choice (sent as an empty string or the
     * `none` sentinel by the select) to null so the nullable rule applies.
     */
    #[Override]
    protected function prepareForValidation(): void
    {
        $failover = $this->input('failover_provider');

        if ($failover === '' || $failover === 'none') {
            $this->merge(['failover_provider' => null]);
        }
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function messages(): array
    {
        return [
            'hard_budget_usd.gte' => 'The hard cap must be greater than or equal to the soft cap.',
        ];
    }
}
