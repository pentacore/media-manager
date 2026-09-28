<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use App\Ai\Middleware\AnswerOnFinalStep;
use App\Ai\Middleware\EnforceBudgetEachStep;
use App\Ai\Middleware\StopWhenClientDisconnected;
use Generator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * Shared behaviour of a read-only HasStructuredOutput sub-agent that a
 * streamed MediaAgent turn delegates to through AgentTool::stream().
 *
 * Use it together with Promptable and let its stream() win:
 * `use ActsAsStructuredSubAgent, Promptable { ActsAsStructuredSubAgent::stream insteadof Promptable; }`
 */
trait ActsAsStructuredSubAgent
{
    /**
     * Wrap every generation step: refuse a step once the hard budget is
     * crossed mid-run, force a plain answer on the final allowed step, and
     * stop at the next step once the chat client disconnected.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new AnswerOnFinalStep, new EnforceBudgetEachStep, new StopWhenClientDisconnected];
    }

    /**
     * laravel/ai 1.0 refuses to stream structured output, and a streamed
     * parent run delegates through AgentTool::stream(). Run the structured
     * prompt instead and hand its JSON back as the stream's only text, so
     * the parent receives the findings on the streaming path too.
     *
     * @param  array<int, mixed>  $attachments
     */
    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $meta = new Meta;

        $streamableAgentResponse = new StreamableAgentResponse((string) Str::uuid7(), function () use (&$streamableAgentResponse, $meta, $prompt, $attachments, $provider, $model, $timeout): Generator {
            $agentResponse = $this->prompt($prompt, $attachments, $provider, $model, $timeout);

            $streamableAgentResponse->invocationId = $agentResponse->invocationId;
            $meta->provider = $agentResponse->meta->provider;
            $meta->model = $agentResponse->meta->model;

            yield new TextDelta(Str::lower((string) Str::uuid7()), Str::lower((string) Str::uuid7()), $agentResponse->text, Date::now()->getTimestamp());
        }, $meta);

        return $streamableAgentResponse;
    }
}
