<?php

declare(strict_types=1);

namespace App\Ai\Tools\Workflow;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

class ProposeWorkflowTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Propose a batch of related destructive operations as a single workflow. Use this INSTEAD of calling multiple destructive tools when you have 3+ operations that should be confirmed together. The user will see a confirm card; on confirm, you will be re-invoked with instructions to execute each step.';
    }

    public function risk(): Risk
    {
        // The tool itself only stores the proposal — actual destructive work
        // happens after the user confirms via the chat UI continuation flow.
        return Risk::Read;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();
        $rationale = (string) ($args['rationale'] ?? '');
        $steps = is_array($args['steps'] ?? null) ? $args['steps'] : [];

        $workflow = AiProposedWorkflow::create([
            'id' => (string) Str::uuid7(),
            'user_id' => auth()->id(),
            'conversation_id' => null,
            'rationale' => Str::limit($rationale, 1000, ''),
            'steps' => $steps,
            'status' => AiProposedWorkflowStatus::Proposed,
        ]);

        return [
            'status' => 'awaiting_confirmation',
            'workflow_id' => $workflow->id,
            'rationale' => $workflow->rationale,
            'steps' => $workflow->steps,
            'message' => 'Workflow proposed. The user will confirm or decline via the chat UI; do not call any further destructive tools until you receive an "approved" continuation message.',
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'rationale' => $schema->string()
                ->description('Plain-English summary of what the user asked and why these steps achieve it.')
                ->required(),
            'steps' => $schema->array()
                ->description('Ordered list of {action: string, target: string, reason: string} objects describing each step. Example: [{"action": "delete_series", "target": "Demo Show (id 42)", "reason": "Unwatched in 8 months."}].')
                ->required(),
        ];
    }
}
