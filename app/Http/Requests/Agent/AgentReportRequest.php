<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use App\Enums\HostCheckStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;

/**
 * The agent's minute report (see the host agent contract). Keys without a rule are dropped by validated(), so newer agents can add fields.
 */
#[TypefinderIgnore]
class AgentReportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $byteCount = ['exclude_if:metrics.filesystems.*.unresponsive,true', 'required', 'integer', 'min:0'];

        return [
            'schema' => ['required', 'integer', 'min:1'],
            'agent_version' => ['required', 'string', 'max:64'],
            'sent_at' => ['nullable', 'date'],
            'config_version' => ['required', 'integer', 'min:0'],
            'host' => ['required', 'array'],
            'host.hostname' => ['required', 'string', 'max:255'],
            'host.os' => ['nullable', 'string', 'max:255'],
            'host.kernel' => ['nullable', 'string', 'max:255'],
            'host.arch' => ['nullable', 'string', 'max:32'],
            'host.uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'metrics' => ['required', 'array'],
            'metrics.cpu_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'metrics.load' => ['required', 'array', 'size:3'],
            'metrics.load.*' => ['required', 'numeric', 'min:0'],
            'metrics.memory' => ['required', 'array'],
            'metrics.memory.total_bytes' => ['required', 'integer', 'min:0'],
            'metrics.memory.available_bytes' => ['required', 'integer', 'min:0'],
            'metrics.memory.swap_total_bytes' => ['required', 'integer', 'min:0'],
            'metrics.memory.swap_free_bytes' => ['required', 'integer', 'min:0'],
            'metrics.oom_kills_total' => ['required', 'integer', 'min:0'],
            'metrics.filesystems' => ['present', 'array', 'max:200'],
            'metrics.filesystems.*.mount' => ['required', 'string', 'max:1024'],
            'metrics.filesystems.*.device' => ['nullable', 'string', 'max:1024'],
            'metrics.filesystems.*.fstype' => ['nullable', 'string', 'max:64'],
            'metrics.filesystems.*.unresponsive' => ['sometimes', 'boolean'],
            'metrics.filesystems.*.total_bytes' => $byteCount,
            'metrics.filesystems.*.used_bytes' => $byteCount,
            'metrics.filesystems.*.available_bytes' => $byteCount,
            'metrics.failed_units' => ['nullable', 'array', 'max:500'],
            'metrics.failed_units.*' => ['required', 'string', 'max:255'],
            'metrics.containers' => ['nullable', 'array', 'max:500'],
            'metrics.containers.*.name' => ['required', 'string', 'max:255'],
            'metrics.containers.*.state' => ['required', 'string', 'max:32'],
            'metrics.containers.*.health' => ['nullable', 'string', 'max:32'],
            'metrics.containers.*.restart_count' => ['required', 'integer', 'min:0'],
            'checks' => ['present', 'array', 'max:200'],
            'checks.*.id' => ['required', 'uuid'],
            'checks.*.type' => ['required', 'string', 'max:64'],
            'checks.*.status' => ['required', HostCheckStatus::validationRule()],
            'checks.*.message' => ['nullable', 'string', 'max:2000'],
            'checks.*.observed' => ['nullable', 'array'],
        ];
    }
}
