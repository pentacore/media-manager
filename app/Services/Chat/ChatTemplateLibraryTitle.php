<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\ChatTemplateVariableType;

/**
 * A series or movie picked for a template variable, resolved from the
 * active connection's index. The id is the service-native id GetMediaTool
 * accepts as item_id.
 */
final readonly class ChatTemplateLibraryTitle
{
    public function __construct(
        public ChatTemplateVariableType $type,
        public string $title,
        public ?int $year,
        public int $id,
    ) {}

    /**
     * @param  list<string>  $parts  Requested parts; empty means title.
     */
    public function format(array $parts): string
    {
        $pieces = array_map(fn (string $part): ?string => match ($part) {
            'title' => $this->title,
            'year' => $this->year === null ? null : sprintf('(%d)', $this->year),
            'id' => sprintf('(%s %d)', $this->type === ChatTemplateVariableType::Series ? 'Sonarr series id' : 'Radarr movie id', $this->id),
            default => null,
        }, $parts === [] ? ['title'] : $parts);

        return implode(' ', array_filter($pieces, static fn (?string $piece): bool => $piece !== null));
    }
}
