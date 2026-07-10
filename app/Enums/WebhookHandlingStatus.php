<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum WebhookHandlingStatus: string
{
    use EnumUtils;

    /** A handler branch matched and ran. */
    case Handled = 'handled';

    /** A handler exists but the event type was not actionable. */
    case Ignored = 'ignored';

    /** No handler is registered for the service type. */
    case NoHandler = 'no_handler';

    /** Processing exhausted retries and failed. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Handled => 'Handled',
            self::Ignored => 'Ignored',
            self::NoHandler => 'No handler',
            self::Failed => 'Failed',
        };
    }
}
