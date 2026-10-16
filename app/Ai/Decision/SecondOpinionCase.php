<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Services\Actions\ActionDescription;
use Illuminate\Support\Str;

/**
 * The case file a second opinion is given on: the proposed action as the
 * approver would see it, the proposer's rationale and the triggering event.
 * No tools, no lookups — the verifier judges only what is here.
 */
final readonly class SecondOpinionCase
{
    private const int PAYLOAD_CHARS = 6000;

    private const int ACTION_PAYLOAD_CHARS = 2000;

    /**
     * @param  array<string, mixed>  $payload  the action payload after connection pinning
     * @param  array<string, mixed>  $eventPayload  the triggering event's payload snapshot
     */
    public function __construct(
        public string $actionType,
        public string $actionLabel,
        public ActionDescription $actionDescription,
        public array $payload,
        public string $rationale,
        public string $sourceService,
        public ?string $eventType = null,
        public array $eventPayload = [],
    ) {}

    public function toPrompt(): string
    {
        $details = implode("\n", array_map(
            static fn (array $detail): string => sprintf('- %s: %s', $detail['label'], $detail['value']),
            $this->actionDescription->details,
        ));

        return sprintf(
            <<<'PROMPT'
PROPOSED ACTION
Type: %s (%s)
Target: %s (%s)
Effect: %s
%s
Payload: %s

PROPOSER'S RATIONALE
<untrusted_proposer_rationale>
%s
</untrusted_proposer_rationale>

TRIGGERING EVENT
Service: %s
Event type: %s
<untrusted_webhook_payload>
%s
</untrusted_webhook_payload>

Should this action run as proposed?
PROMPT,
            $this->actionType,
            $this->actionLabel,
            $this->actionDescription->title,
            $this->actionDescription->verified ? 'name verified by the server' : 'name NOT verified by the server',
            $this->actionDescription->description,
            $details === '' ? '' : "Details:\n".$details,
            $this->json($this->payload, self::ACTION_PAYLOAD_CHARS),
            $this->rationale,
            $this->sourceService,
            $this->eventType ?? 'unknown',
            $this->json($this->eventPayload, self::PAYLOAD_CHARS),
        );
    }

    /**
     * @return array{action: string, target: string, effect: string, rationale: string, event: string, payload: string}
     */
    public function toClassificationState(): array
    {
        return [
            'action' => sprintf('%s (%s)', $this->actionType, $this->actionLabel),
            'target' => $this->actionDescription->title,
            'effect' => $this->actionDescription->description,
            'rationale' => Str::limit($this->rationale, 1000),
            'event' => sprintf('%s %s', $this->sourceService, $this->eventType ?? 'unknown'),
            'payload' => Str::limit((string) json_encode($this->eventPayload), 4000),
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value, int $limit): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '{}' : Str::limit($json, $limit, "\n… (truncated)");
    }
}
