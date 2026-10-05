<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\ChatTemplatePreviewMode;
use App\Enums\ChatTemplateVariableType;
use App\Models\ChatTemplate;
use App\Models\ServiceConnection;
use Illuminate\Validation\ValidationException;

/**
 * Turns a template into message text. render() fills saved templates from
 * user values (already shape-checked by RenderChatTemplateRequest) and
 * resolves library ids; preview() renders an unsaved editor draft with
 * sample values or placeholder names and never touches the library.
 */
final readonly class ChatTemplateRenderer
{
    public const int MAX_LENGTH = 4000;

    /** Smallest value a number variable accepts, as a fill-in value or a default. */
    public const int MIN_NUMBER = 0;

    /** Largest value a number variable accepts, as a fill-in value or a default. */
    public const int MAX_NUMBER = 100000;

    /** Sample title an example preview shows for series variables. */
    private const string SAMPLE_SERIES_TITLE = 'The Show';

    /** Sample title an example preview shows for movie variables. */
    private const string SAMPLE_MOVIE_TITLE = 'The Movie';

    /** Sample year an example preview shows for series and movie variables. */
    private const int SAMPLE_YEAR = 2020;

    /** Sample library id an example preview shows for series and movie variables. */
    private const int SAMPLE_LIBRARY_ID = 1234;

    /** Value an example preview shows for a number variable without a default. */
    private const string SAMPLE_NUMBER = '1';

    public function __construct(
        private ChatTemplateParser $chatTemplateParser,
        private ChatTemplateLibrary $chatTemplateLibrary,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function render(ChatTemplate $chatTemplate, array $values): string
    {
        $resolved = [];
        $errors = [];

        foreach ($chatTemplate->variables as $variable) {
            $name = $variable['name'];
            $type = ChatTemplateVariableType::from($variable['type']);
            $value = $values[$name] ?? null;

            if (! $type->isLibrary()) {
                $resolved[$name] = $type === ChatTemplateVariableType::Number
                    ? (string) (int) $value
                    : trim(is_scalar($value) ? (string) $value : '');

                continue;
            }

            if (! $this->chatTemplateLibrary->activeConnection($type) instanceof ServiceConnection) {
                $errors[sprintf('values.%s', $name)] = $type === ChatTemplateVariableType::Series ? 'Sonarr is not connected.' : 'Radarr is not connected.';

                continue;
            }

            $chatTemplateLibraryTitle = $this->chatTemplateLibrary->find($type, is_numeric($value) ? (int) $value : 0);

            if (! $chatTemplateLibraryTitle instanceof ChatTemplateLibraryTitle) {
                $errors[sprintf('values.%s', $name)] = $type === ChatTemplateVariableType::Series
                    ? 'That series is no longer in your library.'
                    : 'That movie is no longer in your library.';

                continue;
            }

            $resolved[$name] = $chatTemplateLibraryTitle;
        }

        throw_if($errors !== [], ValidationException::withMessages($errors));

        $text = implode('', array_map(
            static function (string|ChatTemplateToken $segment) use ($resolved): string {
                if (! $segment instanceof ChatTemplateToken) {
                    return $segment;
                }

                $value = $resolved[$segment->name] ?? $segment->raw;

                return $value instanceof ChatTemplateLibraryTitle ? $value->format($segment->parts) : $value;
            },
            $this->chatTemplateParser->parse($chatTemplate->body)->segments,
        ));

        throw_if(mb_strlen($text) > self::MAX_LENGTH, ValidationException::withMessages([
            'values' => sprintf('The filled-in message is longer than %d characters.', self::MAX_LENGTH),
        ]));

        return $text;
    }

    /**
     * @param  array<int, mixed>  $variables  Unsaved editor rows; may be incomplete.
     * @return list<array{kind: 'text'|'placeholder', value: string}>
     */
    public function preview(string $body, array $variables, ChatTemplatePreviewMode $chatTemplatePreviewMode): array
    {
        $byName = [];

        foreach ($variables as $variable) {
            if (is_array($variable) && is_string($variable['name'] ?? null)) {
                $byName[$variable['name']] ??= $variable;
            }
        }

        $segments = [];

        foreach ($this->chatTemplateParser->parse($body)->segments as $segment) {
            $piece = $segment instanceof ChatTemplateToken
                ? ['kind' => 'placeholder', 'value' => $this->previewToken($segment, $byName[$segment->name] ?? null, $chatTemplatePreviewMode)]
                : ['kind' => 'text', 'value' => $segment];

            $last = array_key_last($segments);

            if ($piece['kind'] === 'text' && $last !== null && $segments[$last]['kind'] === 'text') {
                $segments[$last]['value'] = sprintf('%s%s', $segments[$last]['value'], $piece['value']);

                continue;
            }

            $segments[] = $piece;
        }

        return $segments;
    }

    /**
     * Example values reuse ChatTemplateLibraryTitle::format() for series and
     * movies, so the preview reads exactly like a rendered message.
     *
     * @param  array<string, mixed>|null  $variable
     */
    private function previewToken(ChatTemplateToken $chatTemplateToken, ?array $variable, ChatTemplatePreviewMode $chatTemplatePreviewMode): string
    {
        $label = is_string($variable['label'] ?? null) && trim($variable['label']) !== '' ? trim($variable['label']) : $chatTemplateToken->name;
        $placeholder = $chatTemplateToken->parts === []
            ? sprintf('[%s]', $label)
            : sprintf('[%s: %s]', $label, implode(', ', $chatTemplateToken->parts));
        $type = ChatTemplateVariableType::tryFrom(is_string($variable['type'] ?? null) ? $variable['type'] : '');

        if ($chatTemplatePreviewMode === ChatTemplatePreviewMode::Names || ! $type instanceof ChatTemplateVariableType) {
            return $placeholder;
        }

        $default = is_scalar($variable['default'] ?? null) ? trim((string) $variable['default']) : '';

        return match ($type) {
            ChatTemplateVariableType::Series => new ChatTemplateLibraryTitle($type, self::SAMPLE_SERIES_TITLE, self::SAMPLE_YEAR, self::SAMPLE_LIBRARY_ID)->format($chatTemplateToken->parts),
            ChatTemplateVariableType::Movie => new ChatTemplateLibraryTitle($type, self::SAMPLE_MOVIE_TITLE, self::SAMPLE_YEAR, self::SAMPLE_LIBRARY_ID)->format($chatTemplateToken->parts),
            ChatTemplateVariableType::Number => $default !== '' ? $default : self::SAMPLE_NUMBER,
            ChatTemplateVariableType::Choice => $default !== '' ? $default : ($this->firstOption($variable['options'] ?? null) ?? $placeholder),
            ChatTemplateVariableType::Text => $default !== '' ? $default : $placeholder,
        };
    }

    private function firstOption(mixed $options): ?string
    {
        if (! is_array($options)) {
            return null;
        }

        foreach ($options as $option) {
            if (is_scalar($option) && trim((string) $option) !== '') {
                return trim((string) $option);
            }
        }

        return null;
    }
}
