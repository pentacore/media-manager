<?php

declare(strict_types=1);

namespace App\Http\Controllers\Actions;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActionTypeConfigResource;
use App\Models\ActionTypeConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
