<?php

declare(strict_types=1);

namespace App\Services\Chat;

/**
 * One {{name}} or {{name:part,part}} placeholder in a template body.
 */
final readonly class ChatTemplateToken
{
    /**
     * @param  list<string>  $parts  Requested parts in written order; empty when none were written.
     */
    public function __construct(
        public string $name,
        public array $parts,
        public string $raw,
    ) {}
}
