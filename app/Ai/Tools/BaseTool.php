<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\Risk;
use App\Enums\AiMode;
use App\Models\ActionRequest;
use App\Services\Actions\ActionDescriber;
use App\Services\Actions\ActionDescription;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Actions\UndescribableAction;
use App\Settings\AiSettings;
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
                'message' => 'The tool failed. Tell the user what you were trying to do and suggest they try again.',
            ]);
        }

        if ($this->risk() === Risk::Destructive) {
            return $this->queueAsActionRequest($result);
        }

        return $this->safeEncode($result);
    }

    /**
     * Subclass entry point. Read/SafeWrite tools return any array. Destructive
     * tools must return ['type' => string, 'target_service' => string, 'payload' => array]
     * and may add 'description' (ActionDescription), 'fallback_title' (string, the
     * model's name for the target), 'origin' (string, default 'chat').
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
        $type = (string) ($candidate['type'] ?? '');
        $payload = is_array($candidate['payload'] ?? null) ? $candidate['payload'] : [];

        try {
            $description = $this->describeCandidate($type, $payload, $candidate);
        } catch (UndescribableAction $undescribableAction) {
            return $this->safeEncode([
                'queued' => false,
                'reason' => 'undescribable_action',
                'message' => sprintf('%s Tell the user the action could not be queued.', $undescribableAction->getMessage()),
            ]);
        }

        $forceRequiresApproval = ($candidate['force_requires_approval'] ?? null) === true ? true : null;
        $deferExecution = ($candidate['defer_execution'] ?? false) === true;
        $dispatch = fn (): ?ActionRequest => $actionOrchestrator->dispatch(
            type: $type,
            sourceService: (string) ($candidate['source_service'] ?? 'ai'),
            targetService: (string) ($candidate['target_service'] ?? ''),
            payload: $payload,
            description: $description,
            forceRequiresApproval: $forceRequiresApproval,
            deferExecution: $deferExecution,
            origin: (string) ($candidate['origin'] ?? 'chat'),
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
     * Tools with bespoke wording (subtitle operations, media replacement) hand
     * over a ready description; every other type is worded by the shared
     * ActionDescriber and attributed to the chat user. `fallback_title` is the
     * model's name for the target and only ever used, unverified, when the
     * server cannot resolve it. A candidate with no description whose type the
     * describer does not know throws UndescribableAction, which handle() turns
     * into the undescribable_action response.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $candidate
     */
    private function describeCandidate(string $type, array $payload, array $candidate): ActionDescription
    {
        if (($candidate['description'] ?? null) instanceof ActionDescription) {
            return $candidate['description'];
        }

        $fallbackTitle = $candidate['fallback_title'] ?? null;

        return resolve(ActionDescriber::class)
            ->describe($type, $payload, is_string($fallbackTitle) ? $fallbackTitle : null)
            ->because($this->requestedInChat());
    }

    protected function requestedInChat(): string
    {
        return sprintf('Requested in chat by %s.', auth()->user()?->name ?? 'an unknown user');
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
