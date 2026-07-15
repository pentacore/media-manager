<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum MediaReplacementScope: string
{
    use EnumUtils;

    case Anime = 'anime';
    case Tv = 'tv';
    case Movie = 'movie';
}
