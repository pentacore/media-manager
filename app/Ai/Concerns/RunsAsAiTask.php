<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use App\Ai\ModelSelection;
use App\Ai\OpenRouterRequestOptions;
use App\Ai\ProviderDriver;
use App\Ai\ReasoningOptions;
use App\Ai\ResolvedSelection;
use App\Ai\TaskModelResolver;
use App\Enums\AiTask;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Ai;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\Anthropic\AnthropicSchemaSanitizer;
use Laravel\Ai\ObjectSchema;
use LogicException;
use ReflectionClass;

/**
 * An agent that runs as one AI task: its model comes from TaskModelResolver
 * (UsesFailoverChain reads modelSelection()) and its providerOptions() send
 * the task's reasoning level translated for whichever provider in the
 * failover chain is being called, plus OpenRouter routing.
 */
trait RunsAsAiTask
{
    abstract public function aiTask(): AiTask;

    /**
     * The webhook event key a decision run is about, for event overrides.
     */
    protected function aiTaskEventKey(): ?string
    {
        return null;
    }

    /**
     * Whether OpenAI should stream a reasoning summary (the chat shows it).
     */
    protected function summarizesReasoning(): bool
    {
        return false;
    }

    public function resolvedSelection(): ResolvedSelection
    {
        return resolve(TaskModelResolver::class)->resolve($this->aiTask(), $this->aiTaskEventKey());
    }

    public function modelSelection(): ModelSelection
    {
        return $this->resolvedSelection()->modelSelection();
    }

    public function model(): string
    {
        return $this->resolvedSelection()->model;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $resolvedSelection = $this->resolvedSelection();
        [$providerName, $model] = $this->chainEntryFor($provider instanceof Lab ? $provider->value : $provider, $resolvedSelection);

        $options = resolve(ReasoningOptions::class)->for(
            $providerName,
            $model,
            $resolvedSelection->reasoning,
            $this->summarizesReasoning(),
            $this->declaredMaxTokens(),
        );

        // The SDK merges providerOptions shallowly, so an Anthropic
        // output_config would drop structured output's format: rebuild it
        // exactly as BuildsTextRequests does.
        if (isset($options['output_config']) && $this instanceof HasStructuredOutput) {
            $options['output_config']['format'] = [
                'type' => 'json_schema',
                'schema' => AnthropicSchemaSanitizer::sanitize(
                    new ObjectSchema($this->schema(new JsonSchemaTypeFactory))->toSchema()
                ),
            ];
        }

        if (ProviderDriver::of($providerName) === Lab::OpenRouter->value) {
            return [...$options, ...resolve(OpenRouterRequestOptions::class)->routing()];
        }

        return $options;
    }

    /**
     * The configured provider name and model in the failover chain that a
     * providerOptions() call is for. Most gateways pass their driver rather
     * than the configured name, so a name match wins, then a driver match in
     * chain order; a provider outside the chain keeps what it was given and
     * no model.
     *
     * @return array{0: string, 1: string|null}
     */
    private function chainEntryFor(string $provider, ResolvedSelection $resolvedSelection): array
    {
        $failover = resolve(TaskModelResolver::class)->failover();
        $chain = array_values(array_filter([$resolvedSelection->provider, $failover['provider'] ?? null]));

        $providerName = in_array($provider, $chain, true)
            ? $provider
            : array_find($chain, static fn (string $name): bool => ProviderDriver::of($name) === $provider) ?? $provider;

        $model = match (true) {
            $providerName === $resolvedSelection->provider => $resolvedSelection->model,
            $failover !== null && $failover['provider'] === $providerName => $failover['model'] ?? $this->providerDefaultModel($providerName),
            default => null,
        };

        return [$providerName, $model];
    }

    /**
     * The model the SDK falls back to when a provider in the chain has no
     * model (Promptable::getDefaultModelFor()), or null when the provider
     * cannot be resolved.
     */
    private function providerDefaultModel(string $provider): ?string
    {
        try {
            return Ai::textProvider($provider)->defaultTextModel();
        } catch (LogicException) {
            return null;
        }
    }

    private function declaredMaxTokens(): ?int
    {
        $attribute = new ReflectionClass($this)->getAttributes(MaxTokens::class)[0] ?? null;

        return $attribute?->newInstance()->value;
    }
}
