<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use App\Ai\ModelSelection;
use App\Ai\OpenRouterRequestOptions;
use App\Ai\ReasoningOptions;
use App\Ai\ResolvedSelection;
use App\Ai\TaskModelResolver;
use App\Enums\AiTask;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\Anthropic\AnthropicSchemaSanitizer;
use Laravel\Ai\ObjectSchema;
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
        $providerName = $provider instanceof Lab ? $provider->value : $provider;
        $resolvedSelection = $this->resolvedSelection();
        $failover = resolve(TaskModelResolver::class)->failover();

        $model = match (true) {
            $providerName === $resolvedSelection->provider => $resolvedSelection->model,
            $failover !== null && $failover['provider'] === $providerName => $failover['model'],
            default => null,
        };

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
                    (new ObjectSchema($this->schema(new JsonSchemaTypeFactory)))->toSchema()
                ),
            ];
        }

        if ($providerName === Lab::OpenRouter->value) {
            $options = [...$options, ...resolve(OpenRouterRequestOptions::class)->routing()];
        }

        return $options;
    }

    private function declaredMaxTokens(): ?int
    {
        $attribute = (new ReflectionClass($this))->getAttributes(MaxTokens::class)[0] ?? null;

        return $attribute?->newInstance()->value;
    }
}
