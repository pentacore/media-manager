<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaReplacementStatus;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\CompetingGrabSweeper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Backstop for the competing-grab sweep. The executor sweeps synchronously
 * right after blocklisting, but the arr runs the search its blocklist queued
 * seconds later, so a competing grab commonly lands after that sweep. These
 * passes catch it, and also cover a Grab webhook that never arrived.
 */
class SweepCompetingGrabs implements ShouldQueue
{
    use Queueable;

    /**
     * Delay, in seconds, applied to each pass. Spread wide enough to cover a
     * busy arr command queue without polling it.
     *
     * @var list<int>
     */
    public const array PASS_DELAY_SECONDS = [60, 180, 600];

    /**
     * @var list<MediaReplacementStatus>
     */
    private const array TERMINAL_STATUSES = [
        MediaReplacementStatus::Verified,
        MediaReplacementStatus::Failed,
        MediaReplacementStatus::NeedsAttention,
    ];

    public function __construct(
        public int $attemptId,
        public int $pass = 0,
    ) {}

    public static function queueFor(int $attemptId, int $pass = 0): void
    {
        if (! array_key_exists($pass, self::PASS_DELAY_SECONDS)) {
            return;
        }

        self::dispatch($attemptId, $pass)->delay(now()->addSeconds(self::PASS_DELAY_SECONDS[$pass]));
    }

    public function handle(CompetingGrabSweeper $competingGrabSweeper): void
    {
        $mediaReplacementAttempt = MediaReplacementAttempt::find($this->attemptId);

        if (! $mediaReplacementAttempt instanceof MediaReplacementAttempt) {
            return;
        }

        // A terminal attempt has either imported or been given up on; sweeping
        // the queue on its behalf could only remove someone else's download.
        if (in_array($mediaReplacementAttempt->status, self::TERMINAL_STATUSES, true)) {
            return;
        }

        $serviceConnection = $mediaReplacementAttempt->serviceConnection;

        if (! $serviceConnection instanceof ServiceConnection) {
            return;
        }

        $competingGrabSweeper->sweep($serviceConnection, $mediaReplacementAttempt);

        self::queueFor($this->attemptId, $this->pass + 1);
    }
}
