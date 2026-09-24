<?php

declare(strict_types=1);

namespace App\Services\Actions;

use InvalidArgumentException;

/**
 * The human-readable account of an ActionRequest shown on the approval card: a
 * one-line title, a sentence on what will happen and why, and the labelled
 * facts a reviewer needs before approving. `verified` is false when the
 * target's name could not be resolved server-side (it came from an LLM or is
 * missing); ActionOrchestrator then forces the request to Pending.
 */
final readonly class ActionDescription
{
    public const int TITLE_LIMIT = 300;

    public const int DESCRIPTION_LIMIT = 1_000;

    public const int LABEL_LIMIT = 100;

    public const int VALUE_LIMIT = 500;

    public const int DETAIL_LIMIT = 20;

    public string $title;

    public string $description;

    /** @var list<array{label: string, value: string}> */
    public array $details;

    public bool $verified;

    /**
     * @param  array<int, array{label: string, value: string}>  $details
     */
    public function __construct(string $title, string $description, array $details = [], bool $verified = true)
    {
        $title = trim($title);
        $description = trim($description);

        throw_if($title === '', InvalidArgumentException::class, 'An action description needs a title.');
        throw_if($description === '', InvalidArgumentException::class, 'An action description needs a description.');

        $this->title = mb_substr($title, 0, self::TITLE_LIMIT);
        $this->description = mb_substr($description, 0, self::DESCRIPTION_LIMIT);
        $this->details = self::normalizeDetails($details);
        $this->verified = $verified;
    }

    /**
     * Prepend the trigger sentence ("Emby reported X was removed.") to the
     * effect sentence the describer wrote ("Sonarr will delete the series.").
     */
    public function because(string $reason): self
    {
        return new self($this->title, sprintf('%s %s', trim($reason), $this->description), $this->details, $this->verified);
    }

    public function withDetail(string $label, string|int|float|bool|null $value): self
    {
        return new self(
            $this->title,
            $this->description,
            [...$this->details, ['label' => $label, 'value' => self::stringify($value)]],
            $this->verified,
        );
    }

    public function unverified(): self
    {
        return new self($this->title, $this->description, $this->details, verified: false);
    }

    /**
     * @return array{title: string, description: string, details: list<array{label: string, value: string}>, description_verified: bool}
     */
    public function toAttributes(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'details' => $this->details,
            'description_verified' => $this->verified,
        ];
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $details
     * @return list<array{label: string, value: string}>
     */
    private static function normalizeDetails(array $details): array
    {
        $normalized = [];

        foreach ($details as $detail) {
            $label = trim($detail['label']);
            $value = trim($detail['value']);

            if ($label === '') {
                continue;
            }

            if ($value === '') {
                continue;
            }

            $normalized[] = [
                'label' => mb_substr($label, 0, self::LABEL_LIMIT),
                'value' => mb_substr($value, 0, self::VALUE_LIMIT),
            ];

            if (count($normalized) === self::DETAIL_LIMIT) {
                break;
            }
        }

        return $normalized;
    }

    private static function stringify(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            default => (string) $value,
        };
    }
}
