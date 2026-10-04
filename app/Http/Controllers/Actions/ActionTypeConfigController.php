<?php

declare(strict_types=1);

namespace App\Http\Controllers\Actions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Actions\UpdateActionTypeConfigRequest;
use App\Http\Resources\ActionTypeConfigResource;
use App\Models\ActionTypeConfig;
use App\Services\Audit\AuditChanges;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ActionTypeConfigController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Actions/Rules', [
            'rules' => ActionTypeConfigResource::collection(
                ActionTypeConfig::orderBy('type')->get()
            )->toArray($request),
        ]);
    }

    public function update(UpdateActionTypeConfigRequest $updateActionTypeConfigRequest, ActionTypeConfig $actionTypeConfig, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $updateActionTypeConfigRequest->validated();
        $before = AuditChanges::snapshot($actionTypeConfig);

        // The rule and its audit row commit together; a no-op save writes none.
        DB::transaction(function () use ($actionTypeConfig, $validated, $before, $auditLogger): void {
            $actionTypeConfig->update($validated);
            $changes = AuditChanges::between($before, AuditChanges::snapshot($actionTypeConfig));

            if ($changes !== []) {
                $auditLogger->record(
                    'action_rule.updated',
                    $actionTypeConfig,
                    sprintf('Updated the "%s" action rule.', $actionTypeConfig->label),
                    $changes,
                );
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule updated.')]);

        return back();
    }
}
