<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Middleware\AnswerOnFinalStep;
use App\Ai\Middleware\EnforceBudgetEachStep;
use App\Ai\Tools\Arr\FindReplacementCandidatesTool;
use App\Ai\Tools\Arr\GetMediaTool;
use App\Ai\Tools\Arr\InspectMediaFileTool;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Settings\AiSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\RepairToolCalls;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

/**
 * Read-only resolution and inspection of one Sonarr/Radarr media file and its
 * ranked replacement candidates, run as a MediaAgent sub-agent. MediaAgent
 * keeps ReplaceMediaFileTool and acts on the structured findings.
 */
#[MaxSteps(8)]
#[RepairToolCalls]
final class MediaFileInspectorAgent implements Agent, CanActAsTool, HasMiddleware, HasStructuredOutput, HasTools
{
    use Promptable;

    public function name(): string
    {
        return 'InspectMediaFile';
    }

    public function description(): string
    {
        return 'Resolves a movie/series/episode file and inspects its tracks and ranked replacement candidates. Read-only. In the task, give the title (and season/episode), the service, and any subtitle-language override.';
    }

    public function model(): string
    {
        return resolve(AiSettings::class)->subAgentModel();
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
You resolve and inspect one media file for replacement decisions. You cannot change anything.
1. Resolve IDs with SearchMediaTool/GetMediaTool. Never guess an id.
2. Call InspectMediaFileTool. If it reports ambiguous=true, set ambiguous=true, list the choices, and stop.
3. Call FindReplacementCandidatesTool with the language override from the task (or null for configured defaults).
4. Report affected files, subtitle tracks (from InspectMediaFileTool only — never Bazarr), candidate fingerprints in rank order copied exactly, a matching one-line description per candidate, and automatic_candidate exactly as returned (or null).
In target, state the resolved item with the exact ids the tools used (service_connection_id, item_id, season_number, episode_number, absolute_episode_number) so a replacement can be requested without looking them up again.
PROMPT;
    }

    /**
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        return [
            resolve(SearchMediaTool::class),
            resolve(GetMediaTool::class),
            resolve(InspectMediaFileTool::class),
            resolve(FindReplacementCandidatesTool::class),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()->enum(['sonarr', 'radarr'])->required(),
            'target' => $schema->string()->description('The resolved item and the exact ids the tools used.')->required(),
            'ambiguous' => $schema->boolean()->required(),
            'choices' => $schema->array()->items($schema->string())->description('When ambiguous, the possible targets to ask the user about.')->required(),
            'affected_files' => $schema->array()->items($schema->string())->required(),
            'subtitle_tracks' => $schema->array()->items($schema->string())->description('From InspectMediaFileTool only.')->required(),
            'candidate_fingerprints' => $schema->array()->items($schema->string())->description('Ranked, copied exactly.')->required(),
            'candidates' => $schema->array()->items($schema->string())->description('One line per candidate, same order as candidate_fingerprints.')->required(),
            'automatic_candidate' => $schema->string()->description('Fingerprint exactly as returned, or null.')->nullable()->required(),
        ];
    }

    /**
     * Wrap every generation step: refuse a step once the hard budget is
     * crossed mid-run, and force a plain answer on the final allowed step.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new AnswerOnFinalStep, new EnforceBudgetEachStep];
    }
}
