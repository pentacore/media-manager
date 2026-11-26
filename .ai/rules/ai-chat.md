---
paths:
  - 'app/Ai/Agents/MediaAgent.php'
  - 'app/Ai/Chat*.php'
  - 'app/Ai/Conversations/**'
  - 'app/Ai/Routing/**'
  - 'app/Ai/Tools/Arr/**'
  - 'app/Ai/Tools/Bazarr/**'
  - 'app/Ai/Tools/Emby/**'
  - 'app/Ai/Tools/Prowlarr/**'
  - 'app/Ai/Tools/Seerr/**'
  - 'app/Ai/Tools/System/**'
  - 'app/Ai/Tools/Tmdb/**'
  - 'app/Ai/Tools/Trakt/**'
  - 'app/Ai/Tools/Workflow/**'
  - 'app/Concerns/Chat*ValidationRules.php'
  - 'app/Console/Commands/Ai/PruneConversations.php'
  - 'app/Enums/ChatTemplate*.php'
  - 'app/Http/Controllers/AI/Chat*.php'
  - 'app/Http/Controllers/AI/ConversationController.php'
  - 'app/Http/Controllers/Admin/AiConversationController.php'
  - 'app/Http/Requests/AI/*Chat*.php'
  - 'app/Http/Requests/AI/*Conversation*.php'
  - 'app/Http/Resources/ChatTemplateResource.php'
  - 'app/Http/Streaming/**'
  - 'app/Jobs/Ai/GenerateConversationTitle.php'
  - 'app/Models/Chat*.php'
  - 'app/Services/Chat/**'
  - 'database/factories/Chat*.php'
  - 'resources/js/components/AiChatSheet.vue'
  - 'resources/js/components/ai/Chat*.vue'
  - 'resources/js/components/ai/chat*.ts'
  - 'resources/js/components/ai/ConversationPicker.vue'
  - 'resources/js/components/ai/TurnToolGroups.vue'
  - 'resources/js/components/ai/toolGroupSources.ts'
  - 'resources/js/components/chat-templates/**'
  - 'resources/js/composables/useAiChat*.ts'
  - 'resources/js/composables/useChat*.ts'
  - 'resources/js/pages/AI/Chat.vue'
  - 'resources/js/pages/AI/Templates/**'
  - 'resources/js/pages/Admin/AiConversations/**'
  - 'tests/**/*Chat*Test.php'
  - 'tests/**/*Conversation*Test.php'
  - 'tests/**/MediaAgent*Test.php'
  - 'tests/Feature/AI/ChatTemplates/**'
  - 'tests/Feature/AI/Routing/**'
  - 'tests/Feature/Services/Chat/**'
  - 'tests/Unit/Services/Chat/**'
---

# AI chat and tools

The AI chat (`MediaAgent`), its tools under `app/Ai/Tools/**` (all but `Decision/` and `PriceFetcher/`), tool groups, chat templates, `ConversationStore` and the chat UI, in every layer. Agent-wide rules (the tool base, sub-agents, failover, middleware, benchmark replay) are in `ai.md`; a Destructive tool queues an `ActionRequest`, whose rules are in `actions.md`.

## Chat turns: ChatTurnGuard before the agent, ChatFailureResponder after it
`ChatController::send()` and `stream()` apply `ConversationModelOverride::applyToTurn()` first (the rate limit must check the model the turn runs on), then refuse a turn only through `ChatTurnGuard::refuse()` — the requested mode, then the 402 hard cap, the 429 rate limit (skipped when a failover provider can take the turn), the 404 ownership/archive check — build the agent with `ChatTurnGuard::agentFor()`, and answer an agent failure anywhere in `send()` or in `stream()` before its first frame with `ChatFailureResponder::respond()`. Both are action parameters, never constructor properties or container singletons: the guard holds the request's scoped `AiSettings` and changes its mode, and under Octane a controller instance is cached on its route. A new chat endpoint reuses them instead of restating a check (`pendingWorkflow()` uses `conversationIsAvailable()`). `UpstreamErrorEchoArchTest` scans `app/Services/Chat` too, so the responder's reviewed `getMessage()` lines are listed under its path. `ChatTurnPreflightTest`, `ChatTurnGuardTest` and `ChatFailureResponderTest` pin it.

## Chat streams stop before a step once the client leaves
`ChatStreamProtocol` keeps `ignore_user_abort(true)` (a disconnect must never kill the request mid-billing) and calls `ClientConnection::watch()`. MediaAgent stops through laravel/ai's `Stop::when()` on `ClientConnection::disconnected()` (a `flush()` poll, then `connection_aborted()`), checked before every step, the first included, so the stream ends with a `cancelled` StreamEnd, AgentStreamed still fires and the turn is saved as a completed reply flagged `stopped` (`StoppedByUser::response()`). Structured sub-agents still throw `ClientDisconnectedException`. `RecordFailedAgentRun` records the sub-agent run as `stopped`; laravel/ai (1.4+) rethrows it from the parent's `AgentTool` call, so that call fires `ToolFailed` (recorded as `stopped` by `RecordToolFailure` via `StoppedByUser::exception()`) and the parent gets a failed "The tool call failed: …" result; the parent then stops at its next step. The exception is in `dontReport` because laravel/ai reports every tool exception. Never stop a stream by breaking out of the frame loop: abandoning the generator skips the terminal event and loses the usage row.

## The chat panel splits turns from conversation state
`useChatTurns` owns a panel's turns: the thread, sending (streamed or a blocking workflow continuation), the stream's `AbortController` (aborted on stop and on unmount) and inline approve/decline/choose/review. `ChatPanel` owns loading a conversation (it replaces `messages`, keyed by `messageUid()`, then calls `inferChoices()`), the model override and the `ai-chat.{user}.{conversation}` step channel. `useAiChat` holds the module-level shared state: the active conversation, the recent list and the pending step, so a turn that creates a conversation makes it every panel's active one. The panel passes its `mode` and `override` refs in, and a turn reads them when it is sent. `ChatTurnsTest` and `AiChatTest` pin it.

## The AI chat is a sheet on a phone
The chat opens from the header's AI button (`[data-header-ai-chat-sheet]`, `sm:hidden`) and from the phone sidebar's "AI Assistant" button (`[data-sidebar-ai-chat]`, `sm:hidden`, which closes the sidebar first) as the full-width `AiChatSheet`, never by navigating to `/ai/chat`; from `sm` up to the sidebar's 768 px sheet breakpoint the sidebar entry is a link to `/ai/chat` (`[data-sidebar-ai-chat-link]`), matching the header link at that width; desktop keeps the header link and the ⌘J footer button. On the chat page itself neither phone entry opens the sheet (the header button is not rendered, the sidebar entry only closes the sidebar), so a second chat never stacks over the page. The sidebar entry focuses the sidebar trigger before opening the sheet, so closing the chat returns focus there. Because the sheet follows the visual viewport, its close X is positioned inside the sheet below `sm` (an arbitrary child variant on `AiChatSheet`'s `SheetContent` turns SheetContent's viewport-fixed phone X into an absolute one), so it moves with the sheet when iOS pans for the keyboard. On a phone `ChatHeader` keeps the conversation picker and a ⋯ popover (`[data-chat-options]`) with the mode buttons and `ChatModelOptions`, the single model/reasoning editor that the desktop chip's popover (`ChatModelPicker`) renders too. While renaming on a phone the picker hides and the rename row flexes, so it never overlaps the ⋯ or the sheet's fixed close X. The sheet's mode lives in `useAiChat` (`sheetMode`), so it survives the sheet closing, as the page's does. Never fork the model controls (their override logic is `components/ai/chatOverride.ts`); `ChatSheetPhoneTest` and `ChatModelOverrideTest` pin it.
