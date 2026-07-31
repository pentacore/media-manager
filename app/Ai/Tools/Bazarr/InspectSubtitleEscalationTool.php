<?php

declare(strict_types=1);

namespace App\Ai\Tools\Bazarr;

use App\Ai\Risk;
use App\Ai\SubtitleAdvisor\SubtitleAdvisorRunContext;
use App\Ai\Tools\BaseTool;
use App\Models\SubtitleCase;
use App\Services\Bazarr\SubtitleAdvisorProjection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;

final class InspectSubtitleEscalationTool extends BaseTool
{
    public function description(): string
    {
        return 'Inspect the one subtitle replacement escalation bound to this Advisor run. '
            .'Returns a compact, sanitized projection and never accepts media identifiers or connection details.';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $caseId = (int) ($request->toArray()['case_id'] ?? 0);
        $context = $this->context();

        throw_unless(
            $caseId > 0 && $caseId === $context->caseId,
            InvalidArgumentException::class,
            'The requested subtitle case does not match this Advisor run.',
        );

        return resolve(SubtitleAdvisorProjection::class)->forCase(
            SubtitleCase::query()->findOrFail($caseId),
        );
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'case_id' => $schema->integer()
                ->description('The exact subtitle case id supplied in the run prompt.')
                ->required(),
        ];
    }

    private function context(): SubtitleAdvisorRunContext
    {
        $context = app()->bound(SubtitleAdvisorRunContext::class)
            ? resolve(SubtitleAdvisorRunContext::class)
            : null;

        throw_unless(
            $context instanceof SubtitleAdvisorRunContext,
            InvalidArgumentException::class,
            'No subtitle Advisor run context is bound.',
        );

        return $context;
    }
}
