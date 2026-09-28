<?php

declare(strict_types=1);

namespace App\Actions;

use RuntimeException;

final class LastAdminException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('At least one admin must remain.');
    }
}
