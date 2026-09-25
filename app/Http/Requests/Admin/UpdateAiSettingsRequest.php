<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AiMode;
use App\Enums\AiReasoningLevel;
use App\Settings\AiSettings;
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
            'rate_limits_enforced' => ['nullable', 'boolean'],
            'ignored_pricing_providers' => ['nullable', 'array'],
            'ignored_pricing_providers.*' => ['string', Rule::in($this->supportedPricingProviders())],
            'auto_create_pricing_providers' => ['sometimes', 'array'],
            'auto_create_pricing_providers.*' => ['string', Rule::in($this->supportedPricingProviders())],
            'classification_provider' => ['sometimes', 'string', Rule::in(AiSettings::CLASSIFICATION_PROVIDERS)],
            'classification_model' => ['nullable', 'string', 'max:100'],
            'decision_gate_enabled' => ['sometimes', 'boolean'],
            'decision_gate_threshold' => ['sometimes', 'numeric', 'between:0,1'],
            'subtitle_triage_enabled' => ['sometimes', 'boolean'],
            'subtitle_triage_threshold' => ['sometimes', 'numeric', 'between:0,1'],
            'chat_routing_enabled' => ['sometimes', 'boolean'],
            'reranking_provider' => ['sometimes', 'string', Rule::in(AiSettings::RERANKING_PROVIDERS)],
            'reranking_model' => ['nullable', 'string', 'max:100'],
            'sub_agent_model' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Normalize the "None" failover choice (sent as an empty string or the
     * `none` sentinel by the select), a blank `chat_timeout` and blank model
     * overrides to null so the nullable rules apply.
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

        // A blank model field means "use the default", which the nullable rules
        // store as a cleared setting.
        foreach (['classification_model', 'reranking_model', 'sub_agent_model'] as $field) {
            if ($this->has($field) && trim((string) $this->input($field)) === '') {
                $this->merge([$field => null]);
            }
        }

        // The form always posts one blank placeholder entry so an all-unchecked
        // list still reaches the server (as an empty list) instead of vanishing
        // like an absent field, which leaves the saved setting untouched.
        $autoCreate = $this->input('auto_create_pricing_providers');

        if (is_array($autoCreate)) {
            $this->merge(['auto_create_pricing_providers' => array_values(array_filter(
                $autoCreate,
                static fn (mixed $provider): bool => $provider !== null && $provider !== '',
            ))]);
        }
    }

    /**
     * The canonical pricing providers an admin may add to the ignore or
     * auto-create lists.
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
