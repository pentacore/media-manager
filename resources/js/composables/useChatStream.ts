import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';

export interface StreamCallbacks {
    onDelta: (accumulated: string) => void;
    onToolCall?: (toolName: string) => void;
}

interface StreamChatOptions extends StreamCallbacks {
    message: string;
    conversationId: string | null;
    mode: 'advisory' | 'executive';
}

export interface StreamChatResult {
    text: string;
    conversationId: string | null;
}

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

/**
 * Consume the `POST /ai/chat/stream` Server-Sent Events response, accumulating
 * text deltas and surfacing tool-call starts through the supplied callbacks.
 *
 * Event shapes are the Laravel AI SDK's default (non-Vercel) `toArray()` output,
 * emitted verbatim as `data: <json>` lines and terminated by `data: [DONE]`:
 *   - `text_delta`      → `{ delta: string }`
 *   - `tool_call`       → `{ tool_name: string }`
 *   - `error`           → `{ message?: string }`
 *   - `conversation_id` → `{ conversation_id: string }` (controller-appended)
 *
 * The SDK's own events carry NO conversation id, so the controller appends a
 * terminal `conversation_id` event before `[DONE]`. This makes a brand-new
 * conversation's id deterministic instead of forcing callers to guess it from
 * the recent-conversation list.
 */
export async function streamChat(
    options: StreamChatOptions,
): Promise<StreamChatResult> {

    const response = await fetch(AIChatController.stream.url(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'text/event-stream',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            message: options.message,
            conversation_id: options.conversationId,
            mode: options.mode,
        }),
    });

    if (!response.ok || !response.body) {
        const data = await response
            .json()
            .catch(() => ({}) as Record<string, unknown>);
        const message =
            typeof data.error === 'string'
                ? data.error
                : typeof data.message === 'string'
                  ? data.message
                  : `Request failed (${response.status})`;

        throw new Error(message);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let text = '';
    let conversationId = options.conversationId;


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

                const payload = line.slice(5).trim();

                if (payload === '[DONE]') {
                    return { text, conversationId };
                }

                let event: Record<string, unknown>;

                try {
                    event = JSON.parse(payload) as Record<string, unknown>;
                } catch {
                    continue;
                }

                switch (event.type) {
                    case 'text_delta':
                        if (typeof event.delta === 'string') {
                            text += event.delta;
                            options.onDelta(text);
                        }

                        break;
                    case 'tool_call':
                        if (typeof event.tool_name === 'string') {
                            options.onToolCall?.(event.tool_name);
                        }

                        break;
                    case 'conversation_id':
                        if (typeof event.conversation_id === 'string') {
                            conversationId = event.conversation_id;
                        }

                        break;
                    case 'error':
                        throw new Error(
                            typeof event.message === 'string'
                                ? event.message
                                : 'The AI stream reported an error.',
                        );
                    default:
                        break;
                }
            }
        }
    }

    return { text, conversationId };
}
