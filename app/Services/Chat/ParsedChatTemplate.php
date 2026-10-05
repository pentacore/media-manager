<?php

declare(strict_types=1);

namespace App\Services\Chat;

final readonly class ParsedChatTemplate
{
    /**
     * @param  list<string|ChatTemplateToken>  $segments  Literal text and tokens in body order.
     * @param  list<string>  $errors  Grammar problems, one sentence each.
     */
    public function __construct(
        public array $segments,
        public array $errors,
    ) {}

    /**
     * @return list<ChatTemplateToken>
     */
    public function tokens(): array
    {
        return array_values(array_filter(
            $this->segments,
            static fn (string|ChatTemplateToken $segment): bool => $segment instanceof ChatTemplateToken,
        ));
    }

    /**
     * Distinct variable names in order of first appearance.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique(array_map(
            static fn (ChatTemplateToken $chatTemplateToken): string => $chatTemplateToken->name,
            $this->tokens(),
        )));
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
