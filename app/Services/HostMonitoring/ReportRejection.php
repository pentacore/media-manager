<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Models\Server;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;

/**
 * Remembers why the platform last refused an agent's report (a 422), so contract drift between agent and platform
 * reads as such on the server page instead of looking like a silent agent. Only the validation messages are kept,
 * never the payload; they name fields and limits, not the values sent.
 */
final readonly class ReportRejection
{
    /** The `servers.last_report_error` column width. */
    public const int SUMMARY_LIMIT = 500;

    public function record(Server $server, MessageBag $messageBag): void
    {
        $server->forceFill([
            'last_report_error' => self::summary($messageBag),
            'last_report_error_at' => now(),
        ])->save();
    }

    /**
     * The first message, and how many other fields failed, within the column width.
     */
    public static function summary(MessageBag $messageBag): string
    {
        $otherFields = max(0, count($messageBag->keys()) - 1);
        $suffix = $otherFields === 0 ? '' : sprintf(' (and %d more %s)', $otherFields, Str::plural('error', $otherFields));

        return sprintf('%s%s', Str::limit((string) $messageBag->first(), self::SUMMARY_LIMIT - mb_strlen($suffix) - mb_strlen('…'), '…'), $suffix);
    }
}
