<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SettingsGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiFreeUsagePoolRequest;
use App\Http\Requests\Admin\UpdateAiFreeUsagePoolRequest;
use App\Models\AiFreeUsagePool;
use App\Services\Audit\AuditChanges;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AiFreeUsagePoolController extends Controller
{
    public function store(StoreAiFreeUsagePoolRequest $storeAiFreeUsagePoolRequest, AuditLogger $auditLogger): RedirectResponse
    {
        DB::transaction(function () use ($storeAiFreeUsagePoolRequest, $auditLogger): void {
            $aiFreeUsagePool = AiFreeUsagePool::create($storeAiFreeUsagePoolRequest->validated());

            $auditLogger->settingsUpdated(
                SettingsGroup::AiFreeUsagePools,
                [],
                AuditChanges::snapshot($aiFreeUsagePool->refresh()),
                ['operation' => 'created', 'record_id' => $aiFreeUsagePool->id],
                sprintf('Added AI free usage pool "%s".', $aiFreeUsagePool->name),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Free usage pool added.')]);

        return to_route('admin.ai-prices.index');
    }

    public function update(UpdateAiFreeUsagePoolRequest $updateAiFreeUsagePoolRequest, AiFreeUsagePool $aiFreeUsagePool, AuditLogger $auditLogger): RedirectResponse
    {
        $before = AuditChanges::snapshot($aiFreeUsagePool);

        DB::transaction(function () use ($updateAiFreeUsagePoolRequest, $aiFreeUsagePool, $auditLogger, $before): void {
            $aiFreeUsagePool->update($updateAiFreeUsagePoolRequest->validated());

            $auditLogger->settingsUpdated(
                SettingsGroup::AiFreeUsagePools,
                $before,
                AuditChanges::snapshot($aiFreeUsagePool),
                ['operation' => 'updated', 'record_id' => $aiFreeUsagePool->id],
                sprintf('Updated AI free usage pool "%s".', $aiFreeUsagePool->name),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Free usage pool updated.')]);

        return to_route('admin.ai-prices.index');
    }

    public function destroy(AiFreeUsagePool $aiFreeUsagePool, AuditLogger $auditLogger): RedirectResponse
    {
        $before = AuditChanges::snapshot($aiFreeUsagePool);

        DB::transaction(function () use ($aiFreeUsagePool, $auditLogger, $before): void {
            $aiFreeUsagePool->delete();

            $auditLogger->settingsUpdated(
                SettingsGroup::AiFreeUsagePools,
                $before,
                [],
                ['operation' => 'deleted', 'record_id' => $aiFreeUsagePool->id],
                sprintf('Removed AI free usage pool "%s".', $aiFreeUsagePool->name),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Free usage pool removed.')]);

        return to_route('admin.ai-prices.index');
    }
}
