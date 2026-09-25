<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum AiUsageKind: string
{
    use EnumUtils;

    case Text = 'text';
    case Embeddings = 'embeddings';
    case Reranking = 'reranking';
    case Classification = 'classification';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Agent runs',
            self::Embeddings => 'Embeddings',
            self::Reranking => 'Reranking',
            self::Classification => 'Classification',
        };
    }
}
