<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiFreeUsagePoolRequest;
use App\Http\Requests\Admin\UpdateAiFreeUsagePoolRequest;
use App\Models\AiFreeUsagePool;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class AiFreeUsagePoolController extends Controller
{
    public function store(StoreAiFreeUsagePoolRequest $storeAiFreeUsagePoolRequest): RedirectResponse
    {
        AiFreeUsagePool::create($storeAiFreeUsagePoolRequest->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Free usage pool added.')]);

        return to_route('admin.ai-prices.index');
    }

    public function update(UpdateAiFreeUsagePoolRequest $updateAiFreeUsagePoolRequest, AiFreeUsagePool $aiFreeUsagePool): RedirectResponse
    {
        $aiFreeUsagePool->update($updateAiFreeUsagePoolRequest->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Free usage pool updated.')]);

        return to_route('admin.ai-prices.index');
    }

    public function destroy(AiFreeUsagePool $aiFreeUsagePool): RedirectResponse
    {
        $aiFreeUsagePool->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Free usage pool removed.')]);

        return to_route('admin.ai-prices.index');
    }
}
