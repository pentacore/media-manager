<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\EnrollAgentRequest;
use App\Services\HostMonitoring\EnrollmentTokenSpent;
use App\Services\HostMonitoring\EnrollmentTokenUnknown;
use App\Services\HostMonitoring\ServerEnrollment;
use Illuminate\Http\JsonResponse;

class EnrollController extends Controller
{
    public function __invoke(EnrollAgentRequest $request, ServerEnrollment $serverEnrollment): JsonResponse
    {
        $validated = $request->validated();

        try {
            $enrolled = $serverEnrollment->enroll($validated['enrollment_token'], [
                'hostname' => $validated['hostname'],
                'os' => $validated['os'] ?? null,
                'kernel' => $validated['kernel'] ?? null,
                'arch' => $validated['arch'] ?? null,
                'agent_version' => $validated['agent_version'],
            ]);
        } catch (EnrollmentTokenUnknown) {
            abort(404);
        } catch (EnrollmentTokenSpent) {
            abort(410);
        }

        return response()->json([
            'server_uuid' => $enrolled['server']->uuid,
            'agent_token' => $enrolled['agent_token'],
            'report_interval_seconds' => $enrolled['server']->report_interval_seconds,
        ], 201);
    }
}
