<?php

declare(strict_types=1);

namespace App\Services\Sabnzbd;

use RuntimeException;

/**
 * SABnzbd answered a slot command with HTTP 200 and `status: false` — a
 * refusal, not a transport error. SabnzbdSlotOperator throws this instead of
 * writing an activity/audit row, so callers can word the refusal the same
 * way QueueController::withClient() already does for every other SABnzbd
 * write ("SABnzbd refused the change.").
 */
final class SabnzbdSlotRefused extends RuntimeException {}
