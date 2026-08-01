<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\Risk;
use App\Enums\AiMode;
use App\Models\ActionRequest;
use App\Services\Actions\ActionOrchestrator;
use App\Settings\AiSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

abstract class BaseTool implements Tool
{
    final public function handle(Request $request): string
    {
        if ($this->risk() === Risk::Destructive
            && resolve(AiSettings::class)->mode() === AiMode::Advisory) {
            return $this->safeEncode([
                'error' => 'advisory_mode_blocks_destructive',
                'message' => 'The system is in Advisory mode. Tell the user to switch to Executive mode (Admin → AI Settings) if they want this action executed.',
            ]);
        }

        try {
            $result = $this->execute($request);
        } catch (Throwable $throwable) {
            // Argument KEYS only: tool arguments can carry user chat content
            // (titles, notes, free text) that doesn't belong in the log.
            Log::warning('AI tool failure', [
                'tool' => static::class,
                'risk' => $this->risk()->value,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'request_keys' => array_keys($request->toArray()),
            ]);

            return $this->safeEncode([
                'error' => 'tool_failed',
                'code' => $this->errorCodeFor($throwable),
                // Not `reason`: that key already carries machine tokens like
                // `no_action_type_config` on the queue path, and the agent is
                // told to repeat this one word-for-word to the user.
                'detail' => $this->failureReasonFor($throwable),
                'message' => 'The tool failed. Tell the user what you were trying to do and relay the detail verbatim, so they do not have to read the logs to find out what went wrong, then suggest they try again.',
            ]);
        }

        if ($this->risk() === Risk::Destructive) {
            return $this->queueAsActionRequest($result);
        }

        return $this->safeEncode($result);
    }

    /**
     * Subclass entry point. Read/SafeWrite tools return any array; Destructive
     * tools must return ['type' => string, 'target_service' => string, 'payload' => array].
     *
     * @return array<string, mixed>
     */
    abstract protected function execute(Request $request): array;

    abstract public function risk(): Risk;

    /**
     * @param  array<string, mixed>  $candidate
     */
    protected function queueAsActionRequest(array $candidate): string
    {
        $actionOrchestrator = resolve(ActionOrchestrator::class);

        $forceRequiresApproval = ($candidate['force_requires_approval'] ?? null) === true ? true : null;
        $deferExecution = ($candidate['defer_execution'] ?? false) === true;
        $dispatch = fn (): ?ActionRequest => $actionOrchestrator->dispatch(
            type: (string) ($candidate['type'] ?? ''),
            sourceService: (string) ($candidate['source_service'] ?? 'ai'),
            targetService: (string) ($candidate['target_service'] ?? ''),
            payload: is_array($candidate['payload'] ?? null) ? $candidate['payload'] : [],
            forceRequiresApproval: $forceRequiresApproval,
            deferExecution: $deferExecution,
        );

        if ($deferExecution) {
            $actionRequest = DB::transaction(function () use ($dispatch, $candidate): ?ActionRequest {
                $actionRequest = $dispatch();

                if ($actionRequest instanceof ActionRequest) {
                    $this->actionRequestQueued($actionRequest, $candidate);
                }

                return $actionRequest;
            });
        } else {
            $actionRequest = $dispatch();
        }

        if ($actionRequest === null) {
            return $this->safeEncode([
                'queued' => false,
                'reason' => 'no_action_type_config',
                'message' => 'No matching ActionTypeConfig exists for this action type, or it is disabled. Tell the user to enable the rule in Admin → Action Rules.',
            ]);
        }

        if (! $deferExecution) {
            $this->actionRequestQueued($actionRequest, $candidate);
        }

        return $this->safeEncode([
            'queued' => true,
            'action_request_id' => $actionRequest->id,
            'status' => $actionRequest->status->value,
            'requires_approval' => $actionRequest->requires_approval,
        ]);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    protected function actionRequestQueued(ActionRequest $actionRequest, array $candidate): void
    {
        //
    }

    /**
     * Map a thrown exception to a stable error code the LLM can reason about.
     */
    protected function errorCodeFor(Throwable $throwable): string
    {
        $base = class_basename($throwable);

        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $base) ?: 'unknown');
    }

    /**
     * A short, human-readable cause the agent can repeat to the user, so a
     * failure is visible in chat rather than only in the logs.
     *
     * Deliberately classified rather than passed through: a client exception
     * message embeds the request URL, and an upstream service reached over a
     * query-string API key would leak that key straight into the transcript.
     */
    protected function failureReasonFor(Throwable $throwable): string
    {
        return match (true) {
            $throwable instanceof ConnectionException => 'The service could not be reached, or took too long to respond.',
            $throwable instanceof RequestException => sprintf(
                'The service rejected the request (HTTP %d).',
                $throwable->response->status(),
            ),
            default => 'An unexpected error occurred while running the tool.',
        };
    }

    /**
     * Encode a payload to JSON resiliently. Falls back to a structured error
     * envelope when encoding fails (e.g. invalid UTF-8 in upstream metadata,
     * NaN/Inf, etc.) so handle() never violates its string return contract.
     *
     * @param  array<string, mixed>  $payload
     */
    private function safeEncode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($encoded === false) {
            return '{"error":"tool_failed","code":"encoding_failed","message":"The tool result could not be encoded. Tell the user something went wrong."}';
        }

        return $encoded;
    }
}
