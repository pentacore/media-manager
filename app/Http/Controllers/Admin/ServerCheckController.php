<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\HostCheckType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServerCheckRequest;
use App\Http\Requests\Admin\ToggleServerCheckRequest;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Check management on the server page. `ServerCheckObserver` bumps the server's config_version on every agent-facing change,
 * so the agent refetches its checks after its next report.
 */
class ServerCheckController extends Controller
{
    public function store(StoreServerCheckRequest $storeServerCheckRequest, Server $server, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $storeServerCheckRequest->validated();
        $hostCheckType = HostCheckType::from($validated['type']);

        DB::transaction(function () use ($server, $validated, $hostCheckType, $auditLogger): void {
            $serverCheck = $server->checks()->create([
                'type' => $hostCheckType,
                'params' => $this->params($hostCheckType, $validated['params']),
                'is_enabled' => (bool) ($validated['is_enabled'] ?? true),
            ]);

            $auditLogger->record('server_check.created', $serverCheck, $this->describe('Added', $serverCheck, $server), context: ['server_id' => $server->id]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Check added.')]);

        return back();
    }

    /**
     * The request forces `type` to the stored type, so only the params and the enabled flag change.
     * Params are assigned only when they differ by value: jsonb hands them back in its own key order and with whole
     * floats as integers, and the array cast compares strictly, so re-saving equal params would still count as a
     * change, bump config_version and write an audit row.
     */
    public function update(StoreServerCheckRequest $storeServerCheckRequest, Server $server, ServerCheck $serverCheck, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($serverCheck->server_id === $server->id, 404);

        $validated = $storeServerCheckRequest->validated();

        DB::transaction(function () use ($server, $serverCheck, $validated, $auditLogger): void {
            $before = ['params' => $serverCheck->params, 'is_enabled' => $serverCheck->is_enabled];

            $params = $this->params($serverCheck->type, $validated['params']);

            if (! $this->sameParams($params, $serverCheck->params)) {
                $serverCheck->params = $params;
            }

            if (array_key_exists('is_enabled', $validated)) {
                $serverCheck->is_enabled = (bool) $validated['is_enabled'];
            }

            $serverCheck->save();

            if (! $serverCheck->wasChanged(['params', 'is_enabled'])) {
                return;
            }

            $changes = [];

            foreach ($before as $field => $from) {
                if ($serverCheck->wasChanged($field)) {
                    $changes[$field] = ['from' => $from, 'to' => $serverCheck->{$field}];
                }
            }

            $auditLogger->record('server_check.updated', $serverCheck, $this->describe('Updated', $serverCheck, $server), $changes, ['server_id' => $server->id]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Check saved.')]);

        return back();
    }

    public function toggle(ToggleServerCheckRequest $toggleServerCheckRequest, Server $server, ServerCheck $serverCheck, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($serverCheck->server_id === $server->id, 404);

        $isEnabled = (bool) $toggleServerCheckRequest->validated()['is_enabled'];

        DB::transaction(function () use ($server, $serverCheck, $isEnabled, $auditLogger): void {
            $serverCheck->update(['is_enabled' => $isEnabled]);

            if (! $serverCheck->wasChanged('is_enabled')) {
                return;
            }

            $auditLogger->record(
                $isEnabled ? 'server_check.enabled' : 'server_check.disabled',
                $serverCheck,
                $this->describe($isEnabled ? 'Enabled' : 'Paused', $serverCheck, $server),
                context: ['server_id' => $server->id],
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $isEnabled ? __('Check enabled.') : __('Check paused.')]);

        return back();
    }

    /**
     * The check's open incident stays until the next report: the report rule engine resolves incidents of checks that are gone.
     */
    public function destroy(Server $server, ServerCheck $serverCheck, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($serverCheck->server_id === $server->id, 404);

        DB::transaction(function () use ($server, $serverCheck, $auditLogger): void {
            $serverCheck->delete();

            $auditLogger->record('server_check.deleted', $serverCheck, $this->describe('Removed', $serverCheck, $server), context: ['server_id' => $server->id]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Check removed.')]);

        return back();
    }

    /**
     * Same keys and values, in any key order; numbers compare by value, so a stored `26` equals a submitted `26.0`.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $stored
     */
    private function sameParams(array $params, array $stored): bool
    {
        ksort($stored);

        if (array_keys($params) !== array_keys($stored)) {
            return false;
        }

        foreach ($params as $key => $value) {
            $storedValue = $stored[$key];
            $bothNumbers = (is_int($value) || is_float($value)) && (is_int($storedValue) || is_float($storedValue));

            if ($bothNumbers ? (float) $value !== (float) $storedValue : $value !== $storedValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * Only the type's own keys are kept (key-sorted), blanks are dropped (the agent applies its defaults) and numbers are stored as numbers.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function params(HostCheckType $hostCheckType, array $params): array
    {
        $known = array_filter(
            Arr::only($params, $hostCheckType->paramKeys()),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        foreach ($known as $key => $value) {
            $known[$key] = match ($key) {
                'port', 'timeout_seconds' => (int) $value,
                'warn_hours', 'crit_hours' => (float) $value,
                default => $value,
            };
        }

        ksort($known);

        return $known;
    }

    private function describe(string $verb, ServerCheck $serverCheck, Server $server): string
    {
        return sprintf('%s check "%s" on server "%s".', $verb, $serverCheck->label(), $server->name);
    }
}
