<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AiMode;
use App\Enums\AiReasoningLevel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            // Bounded well above a single provider round-trip (a tool-using
            // turn chains many) but below the PHP/proxy request ceiling that
            // would cut the response off before the timeout could fire.
            'chat_timeout' => ['nullable', 'integer', 'between:30,600'],
            'failover_provider' => ['nullable', 'string', 'in:anthropic,openai,gemini,groq,mistral'],
            'models_dev_pricing_enabled' => ['nullable', 'boolean'],
            'ignored_pricing_providers' => ['nullable', 'array'],
            'ignored_pricing_providers.*' => ['string', Rule::in($this->supportedPricingProviders())],
        ];
    }

    /**
     * Normalize the "None" failover choice (sent as an empty string or the
     * `none` sentinel by the select) and a blank `chat_timeout` to null so the
     * nullable rules apply.
     */
    #[Override]
    protected function prepareForValidation(): void
    {
        $failover = $this->input('failover_provider');

        if ($failover === '' || $failover === 'none') {
            $this->merge(['failover_provider' => null]);
        }

        // A cleared number input posts an empty string, which would fail the
        // integer rule; null instead clears the setting back to the default.
        if ($this->input('chat_timeout') === '') {
            $this->merge(['chat_timeout' => null]);
        }
    }

    /**
     * The canonical pricing providers an admin may add to the ignore list.
     *
     * @return list<string>
     */
    private function supportedPricingProviders(): array
    {
        /** @var array<string, string> $map */
        $map = config('mediamanager.ai.pricing.providers', []);

        return array_values(array_unique(array_values($map)));
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
