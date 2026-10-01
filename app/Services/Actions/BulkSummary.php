<?php

declare(strict_types=1);

namespace App\Services\Actions;

/**
 * The result of one bulk request: counts per outcome and every failed item
 * with its server-side title and reason, plus the summary toast.
 */
final readonly class BulkSummary
{
    /**
     * @param  list<array{id: int|string, title: string, reason: string}>  $failed
     */
    public function __construct(
        public int $started,
        public int $queued,
        public int $skipped,
        public array $failed,
    ) {}

    /**
     * @return array{started: int, queued: int, skipped: int, failed: list<array{id: int|string, title: string, reason: string}>}
     */
    public function toArray(): array
    {
        return [
            'started' => $this->started,
            'queued' => $this->queued,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
        ];
    }

    /**
     * "12 queued for approval, 3 started, 1 failed: Dune (2021) — Radarr is
     * unreachable right now." The started verb is the surface's own
     * ("approved", "paused", "removed", …).
     *
     * @return array{type: 'success'|'info'|'error', message: string}
     */
    public function toast(string $startedVerb = 'started'): array
    {
        $parts = [];

        if ($this->queued > 0) {
            $parts[] = __(':count queued for approval', ['count' => $this->queued]);
        }

        if ($this->started > 0) {
            $parts[] = __(':count :verb', ['count' => $this->started, 'verb' => $startedVerb]);
        }

        if ($this->skipped > 0) {
            $parts[] = __(':count skipped', ['count' => $this->skipped]);
        }

        if ($this->failed !== []) {
            $first = $this->failed[0];
            $more = count($this->failed) - 1;
            $line = __(':count failed: :title — :reason', ['count' => count($this->failed), 'title' => $first['title'], 'reason' => $first['reason']]);
            $parts[] = $more > 0 ? sprintf('%s %s', $line, __('(+:more more)', ['more' => $more])) : $line;
        }

        return [
            'type' => match (true) {
                $this->failed !== [] => 'error',
                $this->started === 0 => 'info',
                default => 'success',
            },
            'message' => $parts === [] ? __('Nothing to do.') : implode(', ', $parts),
        ];
    }

    /**
     * The JSON body every bulk endpoint returns.
     *
     * @return array{started: int, queued: int, skipped: int, failed: list<array{id: int|string, title: string, reason: string}>, toast: array{type: 'success'|'info'|'error', message: string}}
     */
    public function withToast(string $startedVerb = 'started'): array
    {
        return [...$this->toArray(), 'toast' => $this->toast($startedVerb)];
    }
}
