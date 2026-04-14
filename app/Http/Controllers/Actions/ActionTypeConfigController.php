<?php

declare(strict_types=1);

namespace App\Http\Controllers\Actions;

use App\Http\Controllers\Controller;
use App\Models\ActionTypeConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActionTypeConfigController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Actions/Rules', [
            'rules' => ActionTypeConfig::orderBy('type')
                ->get()
                ->map(fn (ActionTypeConfig $actionTypeConfig): array => [
                    'id' => $actionTypeConfig->id,
                    'type' => $actionTypeConfig->type,
                    'label' => $actionTypeConfig->label,
                    'description' => $actionTypeConfig->description,
                    'requires_approval' => $actionTypeConfig->requires_approval,
                    'is_enabled' => $actionTypeConfig->is_enabled,
                ]),
        ]);
    }

    public function update(Request $request, ActionTypeConfig $actionTypeConfig): RedirectResponse
    {
        $validated = $request->validate([
            'requires_approval' => ['required', 'boolean'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        $actionTypeConfig->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule updated.')]);

        return back();
    }
}
