import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
import type { ChatToolCall } from '@/composables/useAiChat';

export type { ChatToolCall } from '@/composables/useAiChat';

export interface StreamCallbacks {
    onText: (accumulated: string) => void;
    onReasoning?: (accumulated: string) => void;
    onToolCall?: (call: ChatToolCall) => void;
}

interface StreamChatOptions extends StreamCallbacks {
    message: string;
    conversationId: string | null;
    mode: 'advisory' | 'executive';
    attachments?: File[];
}

export interface StreamChatResult {
    text: string;
    reasoning: string;
    conversationId: string | null;
}

export interface UseChatStreamReturn {
    streamChat: (options: StreamChatOptions) => Promise<StreamChatResult>;
}

type AgUiEvent = Record<string, unknown> & { type?: string };

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

/**
 * JSON for a text-only turn; multipart once files are attached (the browser
 * sets the multipart boundary itself, so no Content-Type header then).
 */
function requestBody(options: StreamChatOptions): {
    body: BodyInit;
    headers: Record<string, string>;
} {
    if (!options.attachments?.length) {
        return {
            body: JSON.stringify({
                message: options.message,
                conversation_id: options.conversationId,
                mode: options.mode,
            }),
            headers: { 'Content-Type': 'application/json' },
        };
    }

    const form = new FormData();
    form.append('message', options.message);
    form.append('mode', options.mode);

    if (options.conversationId) {
        form.append('conversation_id', options.conversationId);
    }

    options.attachments.forEach((file) => form.append('attachments[]', file));

    return { body: form, headers: {} };
}

/**
 * A BaseTool reports failure inside a successful tool result as
 * `{"error": "..."}`; genuine SDK failures arrive as `metadata.error`.
 */
function toolResultFailed(event: AgUiEvent): boolean {
    const metadata = event.metadata as Record<string, unknown> | undefined;

    if (metadata?.error) {
        return true;
    }

    try {
        const parsed = JSON.parse(String(event.content ?? '')) as Record<
            string,
            unknown
        > | null;

        return typeof parsed?.error === 'string';
    } catch {
        return false;
    }
}

/**
 * Consume `POST /ai/chat/stream` — the Laravel AI SDK's AG-UI protocol
 * (ChatStreamProtocol): RUN_STARTED/FINISHED carry the conversation id as
 * `threadId`; RUN_ERROR ends the run with a user-facing message.
 */
export function useChatStream(): UseChatStreamReturn {
    async function streamChat(
        options: StreamChatOptions,
    ): Promise<StreamChatResult> {
        const { body, headers } = requestBody(options);
        const response = await fetch(AIChatController.stream.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                ...headers,
                Accept: 'text/event-stream',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body,
        });

        if (!response.ok || !response.body) {
            const data = await response
                .json()
                .catch(() => ({}) as Record<string, unknown>);
            const firstError = data.errors
                ? Object.values(data.errors as Record<string, string[]>)[0]?.[0]
                : undefined;

            // `message` is the human-readable explanation (budget cap, rate
            // limit); `error` is either a short code or a generic sentence.
            throw new Error(
                firstError ??
                    (typeof data.message === 'string'
                        ? data.message
                        : typeof data.error === 'string'
                          ? data.error
                          : `Request failed (${response.status})`),
            );
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        const calls = new Map<string, ChatToolCall>();
        let buffer = '';
        let text = '';
        let reasoning = '';
        let conversationId = options.conversationId;

        const handle = (event: AgUiEvent): void => {
            switch (event.type) {
                case 'RUN_STARTED':
                case 'RUN_FINISHED':
                    if (typeof event.threadId === 'string') {
                        conversationId = event.threadId;
                    }

                    break;
                case 'TEXT_MESSAGE_CONTENT':
                    if (typeof event.delta === 'string') {
                        text += event.delta;
                        options.onText(text);
                    }

                    break;
                case 'REASONING_MESSAGE_CONTENT':
                    if (typeof event.delta === 'string') {
                        reasoning += event.delta;
                        options.onReasoning?.(reasoning);
                    }

                    break;
                case 'TOOL_CALL_START': {
                    const call: ChatToolCall = {
                        id: String(event.toolCallId),
                        name: String(event.toolCallName),
                        status: 'running',
                        activity: [],
                    };
                    calls.set(call.id, call);
                    options.onToolCall?.({ ...call });

                    break;
                }
                case 'ACTIVITY_SNAPSHOT': {
                    const call = calls.get(String(event.messageId));
                    const content = event.content as
                        { toolName?: string } | undefined;

                    if (call && content?.toolName) {
                        call.activity = [...call.activity, content.toolName];
                        options.onToolCall?.({ ...call });
                    }

                    break;
                }
                case 'TOOL_CALL_RESULT': {
                    const call = calls.get(String(event.toolCallId));

                    if (call) {
                        call.status = toolResultFailed(event)
                            ? 'failed'
                            : 'done';
                        options.onToolCall?.({ ...call });
                    }

                    break;
                }
                case 'RUN_ERROR':
                    throw new Error(
                        typeof event.message === 'string'
                            ? event.message
                            : 'The AI stream reported an error.',
                    );
                default:
                    break;
            }
        };

        for (;;) {
            const { done, value } = await reader.read();

            if (done) {
                break;
            }

            buffer += decoder.decode(value, { stream: true });

            let boundary: number;

            while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                const rawEvent = buffer.slice(0, boundary);
                buffer = buffer.slice(boundary + 2);

                for (const line of rawEvent.split('\n')) {
                    if (!line.startsWith('data:')) {
                        continue;
                    }

                    let event: AgUiEvent;

                    try {
                        event = JSON.parse(line.slice(5).trim()) as AgUiEvent;
                    } catch {
                        continue;
                    }

                    handle(event);
                }
            }
        }

        return { text, reasoning, conversationId };
    }

    return { streamChat };
}
