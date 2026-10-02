<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use RuntimeException;

/**
 * Another MediaManager write (a member's cancel, a console or queued
 * approve/decline) holds this Seerr request right now.
 */
final class SeerrRequestBusy extends RuntimeException {}
