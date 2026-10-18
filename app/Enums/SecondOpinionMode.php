<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * What a disagreeing second opinion does to an automated proposal of an
 * action type. A failed check always holds, whatever the mode.
 */
enum SecondOpinionMode: string
{
    use EnumUtils;

    case Off = 'off';
    case Hold = 'hold';
    case Reject = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Hold => 'Hold if it disagrees',
            self::Reject => 'Reject if it disagrees',
        };
    }
}
