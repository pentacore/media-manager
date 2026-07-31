<?php

declare(strict_types=1);

namespace App\Services\Actions;

use App\Models\ActionRequest;

final readonly class ActionRequestBrowserPayload
{
    /**
     * @return array<string, mixed>
     */
    public function for(ActionRequest $actionRequest): array
    {
        if ($actionRequest->type !== 'replace_media_file') {
            return $actionRequest->payload;
        }

        $payload = [
            'title' => $this->boundedString($actionRequest, 'title', 300),
            'detail' => $this->boundedString($actionRequest, 'detail', 1_000),
            'service' => $this->boundedString($actionRequest, 'service', 50),
            'scope' => $this->boundedString($actionRequest, 'scope', 100),
            'required_languages' => $this->boundedStringList($actionRequest, 'required_languages', 20, 50),
            'confidence' => $this->safeConfidence($actionRequest),
            'matched_rules' => $this->safeMatchedRules($actionRequest),
            'selection_mode' => $this->boundedString($actionRequest, 'selection_mode', 100),
            'agent_rationale' => $this->boundedString($actionRequest, 'agent_rationale', 1_000),
            'original_history_id' => $this->integer($actionRequest, 'original_history_id'),
            'subtitle_case_id' => $this->integer($actionRequest, 'subtitle_case_id'),
            'affected_file_count' => $this->affectedFileCount($actionRequest),
            'season_pack' => $this->candidateIsSeasonPack($actionRequest),
        ];

        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null,
        );
    }

    private function boundedString(ActionRequest $actionRequest, string $key, int $length): ?string
    {
        $value = $actionRequest->payload[$key] ?? null;

        return is_string($value) ? mb_substr($value, 0, $length) : null;
    }

    /**
     * @return list<string>
     */
    private function boundedStringList(
        ActionRequest $actionRequest,
        string $key,
        int $count,
        int $length,
    ): array {
        $values = $actionRequest->payload[$key] ?? null;

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $value): string => mb_substr($value, 0, $length),
            array_slice(array_filter($values, is_string(...)), 0, $count),
        ));
    }

    /**
     * @return list<string>
     */
    private function safeMatchedRules(ActionRequest $actionRequest): array
    {
        $rules = $actionRequest->payload['matched_rules'] ?? null;

        if (! is_array($rules)) {
            return [];
        }

        $ruleNames = [];

        foreach ($rules as $rule) {
            $ruleName = is_string($rule)
                ? $rule
                : (is_array($rule) && is_string($rule['name'] ?? null) ? $rule['name'] : null);

            if ($ruleName === null) {
                continue;
            }

            if ($ruleName === '') {
                continue;
            }

            $ruleNames[] = mb_substr($ruleName, 0, 200);

            if (count($ruleNames) === 20) {
                break;
            }
        }

        return $ruleNames;
    }

    private function safeConfidence(ActionRequest $actionRequest): int|float|null
    {
        $confidence = $actionRequest->payload['confidence'] ?? null;

        return is_int($confidence) || is_float($confidence)
            ? max(0, min(100, $confidence))
            : null;
    }

    private function integer(ActionRequest $actionRequest, string $key): ?int
    {
        $value = $actionRequest->payload[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    private function affectedFileCount(ActionRequest $actionRequest): int
    {
        $target = $actionRequest->payload['target'] ?? null;

        if (! is_array($target)) {
            return 0;
        }

        $fileIds = $target['episode_file_ids'] ?? $target['movie_file_ids'] ?? null;

        return is_array($fileIds) ? count($fileIds) : 0;
    }

    private function candidateIsSeasonPack(ActionRequest $actionRequest): bool
    {
        $candidate = $actionRequest->payload['candidate'] ?? null;

        return is_array($candidate) && ($candidate['season_pack'] ?? false) === true;
    }
}
