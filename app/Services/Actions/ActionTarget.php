<?php

declare(strict_types=1);

namespace App\Services\Actions;

/**
 * The thing an action acts on, as resolved by ActionTargets: a noun ("series"),
 * a display name, the facts that identify it, and whether the name was
 * confirmed server-side.
 */
final readonly class ActionTarget
{
    /**
     * @param  list<array{label: string, value: string}>  $details
     */
    public function __construct(
        public string $noun,
        public string $name,
        public array $details = [],
        public bool $verified = true,
    ) {}

    public function label(): string
    {
        return sprintf('%s "%s"', $this->noun, $this->name);
    }

    public function describe(string $title, string $effect): ActionDescription
    {
        return new ActionDescription($title, $effect, $this->details, $this->verified);
    }
}
