<?php

declare(strict_types=1);

namespace App\Jobs;

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
     * The attempt lookup and relation load happen before the successor is
     * queued, so a transient database failure at one pass would otherwise drop
     * every remaining pass rather than retry.
     */
    public int $tries = 2;

    /**
     * Paired with $tries because the failure this retry exists for is a transient
     * database blip at the lookup below, and an immediate retry is likely to hit
     * the same blip. 30s matches ExecuteActionRequest and FetchLatestServiceVersion,
     * the siblings whose work is likewise cheap to repeat and worthless to lose;
     * it also stays well inside the gap to this chain's next pass.
     */
    public int $backoff = 30;

    public function __construct(
        public int $attemptId,
        public int $pass = 0,
    ) {}

    public static function queueFor(int $attemptId, int $pass = 0): void
    {
        if (! array_key_exists($pass, self::PASS_DELAY_SECONDS)) {
            return;
        }

        dispatch(new self($attemptId, $pass))->delay(now()->addSeconds(self::PASS_DELAY_SECONDS[$pass]));
    }

    public function handle(CompetingGrabSweeper $competingGrabSweeper): void
    {
        $mediaReplacementAttempt = MediaReplacementAttempt::find($this->attemptId);

        if (! $mediaReplacementAttempt instanceof MediaReplacementAttempt) {
            return;
        }

        $serviceConnection = $mediaReplacementAttempt->serviceConnection;

        if (! $serviceConnection instanceof ServiceConnection) {
            return;
        }

        $competingGrabSweeper->sweep($serviceConnection, $mediaReplacementAttempt);

        // A terminal state may have been produced by our own import before this
        // delayed backstop ran. Sweep once so a competitor whose Grab webhook was
        // lost cannot survive, then stop the chain rather than polling a settled
        // target indefinitely.
        if ($mediaReplacementAttempt->status->isTerminal()) {
            return;
        }

        self::queueFor($this->attemptId, $this->pass + 1);
    }
}
