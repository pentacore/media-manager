<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\Risk;
use App\Enums\AiMode;
use App\Services\Actions\ActionOrchestrator;
use App\Settings\AiSettings;
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
            return json_encode([
                'error' => 'advisory_mode_blocks_destructive',
                'message' => 'The system is in Advisory mode. Tell the user to switch to Executive mode (Admin → AI Settings) if they want this action executed.',
            ]);
        }

        try {
            $result = $this->execute($request);
        } catch (Throwable $throwable) {
            Log::warning('AI tool failure', [
                'tool' => static::class,
                'risk' => $this->risk()->value,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'request' => $request->toArray(),
            ]);

            return json_encode([
                'error' => 'tool_failed',
                'code' => $this->errorCodeFor($throwable),
                'message' => 'The tool failed. Tell the user what you were trying to do and suggest they try again.',
            ]);
        }

        if ($this->risk() === Risk::Destructive) {
            return $this->queueAsActionRequest($result);
        }

        return json_encode($result);
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

        $actionRequest = $actionOrchestrator->dispatch(
            type: (string) ($candidate['type'] ?? ''),
            sourceService: (string) ($candidate['source_service'] ?? 'ai'),
            targetService: (string) ($candidate['target_service'] ?? ''),
            payload: is_array($candidate['payload'] ?? null) ? $candidate['payload'] : [],
        );

        if ($actionRequest === null) {
            return json_encode([
                'queued' => false,
                'reason' => 'no_action_type_config',
                'message' => 'No matching ActionTypeConfig exists for this action type, or it is disabled. Tell the user to enable the rule in Admin → Action Rules.',
            ]);
        }

        return json_encode([
            'queued' => true,
            'action_request_id' => $actionRequest->id,
            'status' => $actionRequest->status->value,
            'requires_approval' => $actionRequest->requires_approval,
        ]);
    }

    /**
     * Map a thrown exception to a stable error code the LLM can reason about.
     */
    protected function errorCodeFor(Throwable $throwable): string
    {
        $base = class_basename($throwable);

        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $base) ?: 'unknown');
    }
}
