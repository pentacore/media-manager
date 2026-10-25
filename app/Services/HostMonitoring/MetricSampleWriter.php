<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Models\Server;
use App\Models\ServerFilesystemSample;
use App\Models\ServerMetricSample;

/**
 * Files a report's host and filesystem figures as 60 s samples under its receive minute. A second report in the same minute overwrites the row.
 */
final readonly class MetricSampleWriter
{
    public function write(Server $server, AgentReport $agentReport): void
    {
        $recordedAt = $agentReport->minute();

        ServerMetricSample::query()->upsert([[
            'server_id' => $server->id,
            'resolution' => ServerMetricSample::MINUTE,
            'recorded_at' => $recordedAt,
            'cpu_percent' => $agentReport->cpuPercent,
            'load1' => $agentReport->load1,
            'memory_used_bytes' => $agentReport->memoryUsedBytes,
            'memory_total_bytes' => $agentReport->memoryTotalBytes,
            'swap_used_bytes' => $agentReport->swapUsedBytes,
            'swap_total_bytes' => $agentReport->swapTotalBytes,
        ]], ['server_id', 'resolution', 'recorded_at'], [
            'cpu_percent', 'load1', 'memory_used_bytes', 'memory_total_bytes', 'swap_used_bytes', 'swap_total_bytes',
        ]);

        $filesystemRows = [];

        foreach ($agentReport->filesystems as $filesystem) {
            if ($filesystem['unresponsive']) {
                continue;
            }

            $filesystemRows[$filesystem['mount']] = [
                'server_id' => $server->id,
                'resolution' => ServerMetricSample::MINUTE,
                'recorded_at' => $recordedAt,
                'mount' => $filesystem['mount'],
                'fstype' => $filesystem['fstype'],
                'total_bytes' => (int) $filesystem['total_bytes'],
                'used_bytes' => (int) $filesystem['used_bytes'],
                'available_bytes' => (int) $filesystem['available_bytes'],
            ];
        }

        if ($filesystemRows === []) {
            return;
        }

        ServerFilesystemSample::query()->upsert(
            array_values($filesystemRows),
            ['server_id', 'resolution', 'mount', 'recorded_at'],
            ['fstype', 'total_bytes', 'used_bytes', 'available_bytes'],
        );
    }
}
