<?php

declare(strict_types=1);

namespace App\Ai\Routing;

/**
 * The tool groups a routed chat turn loads and why, ordered like ToolGroup::cases().
 */
final readonly class RoutedToolGroups
{
    /**
     * @param  array<string, ToolGroupSource>  $sources  keyed by ToolGroup value
     */
    public function __construct(public array $sources) {}

    /**
     * @return list<ToolGroup>
     */
    public function groups(): array
    {
        return array_values(array_filter(
            ToolGroup::cases(),
            fn (ToolGroup $toolGroup): bool => isset($this->sources[$toolGroup->value]),
        ));
    }

    /**
     * @return list<array{group: string, label: string, source: string}>
     */
    public function toArray(): array
    {
        return array_map(fn (ToolGroup $toolGroup): array => [
            'group' => $toolGroup->value,
            'label' => $toolGroup->label(),
            'source' => $this->sources[$toolGroup->value]->value,
        ], $this->groups());
    }

    /**
     * Rebuild from stored message meta; entries with an unknown group or source are dropped.
     */
    public static function fromArray(mixed $stored): ?self
    {
        if (! is_array($stored)) {
            return null;
        }

        $sources = [];

        foreach ($stored as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $toolGroup = is_string($entry['group'] ?? null) ? ToolGroup::tryFrom($entry['group']) : null;
            $source = is_string($entry['source'] ?? null) ? ToolGroupSource::tryFrom($entry['source']) : null;

            if ($toolGroup instanceof ToolGroup && $source instanceof ToolGroupSource) {
                $sources[$toolGroup->value] = $source;
            }
        }

        return new self(self::ordered($sources));
    }

    /**
     * @param  array<string, ToolGroupSource>  $sources
     * @return array<string, ToolGroupSource>
     */
    public static function ordered(array $sources): array
    {
        $ordered = [];

        foreach (ToolGroup::cases() as $toolGroup) {
            if (isset($sources[$toolGroup->value])) {
                $ordered[$toolGroup->value] = $sources[$toolGroup->value];
            }
        }

        return $ordered;
    }
}
