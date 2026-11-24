<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Enums\ChatTemplatePreviewMode;
use App\Enums\ChatTemplateVariableType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AI\DraftChatTemplateRequest;
use App\Http\Requests\AI\StoreChatTemplateRequest;
use App\Http\Requests\AI\UpdateChatTemplateRequest;
use App\Http\Resources\ChatTemplateResource;
use App\Models\ChatTemplate;
use App\Models\User;
use App\Services\Chat\ChatTemplateDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $templates = ChatTemplate::query()
            ->whereBelongsTo($this->owner($request))
            ->orderByDesc('pinned')
            ->orderBy('name')
            ->get();

        return Inertia::render('AI/Templates/Index', [
            'templates' => ChatTemplateResource::collection($templates)->toArray($request),
        ]);
    }

    public function create(): Response
    {
        return $this->createPage('');
    }

    /**
     * "Save as template" posts the message here and gets the editor back with
     * it pre-filled. The route shares create's URL, so a reload is a plain GET.
     */
    public function draft(DraftChatTemplateRequest $draftChatTemplateRequest): Response
    {
        $validated = $draftChatTemplateRequest->validated();

        return $this->createPage((string) $validated['body']);
    }

    public function store(StoreChatTemplateRequest $storeChatTemplateRequest, ChatTemplateDefinition $chatTemplateDefinition): RedirectResponse
    {
        $validated = $storeChatTemplateRequest->validated();

        $this->owner($storeChatTemplateRequest)->chatTemplates()->create($this->attributes($validated, $chatTemplateDefinition));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template saved.')]);

        return to_route('ai.templates.index');
    }

    public function edit(Request $request, ChatTemplate $chatTemplate): Response
    {
        abort_unless($chatTemplate->isOwnedBy($this->owner($request)), 404);

        return Inertia::render('AI/Templates/Edit', [
            'template' => new ChatTemplateResource($chatTemplate)->toArray($request),
            'prefillBody' => '',
            'variableTypes' => $this->variableTypes(),
            'previewModes' => $this->previewModes(),
        ]);
    }

    public function update(UpdateChatTemplateRequest $updateChatTemplateRequest, ChatTemplate $chatTemplate, ChatTemplateDefinition $chatTemplateDefinition): RedirectResponse
    {
        $validated = $updateChatTemplateRequest->validated();

        $chatTemplate->update($this->attributes($validated, $chatTemplateDefinition));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template updated.')]);

        return to_route('ai.templates.index');
    }

    public function destroy(Request $request, ChatTemplate $chatTemplate): RedirectResponse
    {
        abort_unless($chatTemplate->isOwnedBy($this->owner($request)), 404);

        $chatTemplate->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template deleted.')]);

        return back();
    }

    public function pin(Request $request, ChatTemplate $chatTemplate): RedirectResponse
    {
        abort_unless($chatTemplate->isOwnedBy($this->owner($request)), 404);

        $chatTemplate->update(['pinned' => ! $chatTemplate->pinned]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $chatTemplate->pinned ? __('Template pinned.') : __('Template unpinned.'),
        ]);

        return back();
    }

    private function createPage(string $prefillBody): Response
    {
        return Inertia::render('AI/Templates/Edit', [
            'template' => null,
            'prefillBody' => $prefillBody,
            'variableTypes' => $this->variableTypes(),
            'previewModes' => $this->previewModes(),
        ]);
    }

    private function owner(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{name: string, body: string, variables: list<array{name: string, label: string, type: string, default: string|null, options: list<string>|null}>, auto_send: bool, pinned: bool, model_provider: string|null, model: string|null, reasoning: string|null, tool_groups: list<string>|null}
     */
    private function attributes(array $validated, ChatTemplateDefinition $chatTemplateDefinition): array
    {
        return [
            'name' => (string) $validated['name'],
            'body' => (string) $validated['body'],
            'variables' => $chatTemplateDefinition->normalize($validated['variables'] ?? []),
            'auto_send' => (bool) $validated['auto_send'],
            'pinned' => (bool) $validated['pinned'],
            'model_provider' => filled($validated['preset']['model'] ?? null) ? ($validated['preset']['provider'] ?? null) : null,
            'model' => $validated['preset']['model'] ?? null,
            'reasoning' => $validated['preset']['reasoning'] ?? null,
            'tool_groups' => filled($validated['tool_groups'] ?? null) ? array_values($validated['tool_groups']) : null,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function variableTypes(): array
    {
        return array_map(
            static fn (ChatTemplateVariableType $chatTemplateVariableType): array => [
                'value' => $chatTemplateVariableType->value,
                'label' => $chatTemplateVariableType->label(),
            ],
            ChatTemplateVariableType::cases(),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function previewModes(): array
    {
        return array_map(
            static fn (ChatTemplatePreviewMode $chatTemplatePreviewMode): array => [
                'value' => $chatTemplatePreviewMode->value,
                'label' => $chatTemplatePreviewMode->label(),
            ],
            ChatTemplatePreviewMode::cases(),
        );
    }
}
