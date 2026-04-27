<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Bot, Send, User as UserIcon } from 'lucide-vue-next';
import { nextTick, ref, useTemplateRef } from 'vue';
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';

interface ChatMessage {
    role: 'user' | 'assistant';
    text: string;
    ts: number;
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'AI Assistant', href: AIChatController.index.url() },
        ],
    },
});

const messages = ref<ChatMessage[]>([]);
const input = ref('');
const sending = ref(false);
const error = ref<string | null>(null);
const conversationId = ref<string | null>(null);
const scrollRef = useTemplateRef<HTMLDivElement>('scroll');

async function sendMessage() {
    const text = input.value.trim();

    if (!text || sending.value) {
        return;
    }

    messages.value.push({ role: 'user', text, ts: Date.now() });
    input.value = '';
    sending.value = true;
    error.value = null;

    await nextTick();
    scrollRef.value?.scrollTo({
        top: scrollRef.value.scrollHeight,
        behavior: 'smooth',
    });

    try {
        const response = await fetch(AIChatController.send.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN':
                    (
                        document.querySelector(
                            'meta[name="csrf-token"]',
                        ) as HTMLMetaElement | null
                    )?.content ?? '',
            },
            body: JSON.stringify({
                message: text,
                conversation_id: conversationId.value,
            }),
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));

            throw new Error(
                data.error ?? `Request failed (${response.status})`,
            );
        }

        const data = (await response.json()) as {
            text: string;
            conversation_id: string | null;
        };
        conversationId.value = data.conversation_id;
        messages.value.push({
            role: 'assistant',
            text: data.text,
            ts: Date.now(),
        });
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Unknown error';
    } finally {
        sending.value = false;
        await nextTick();
        scrollRef.value?.scrollTo({
            top: scrollRef.value.scrollHeight,
            behavior: 'smooth',
        });
    }
}

function newConversation() {
    conversationId.value = null;
    messages.value = [];
    error.value = null;
}
</script>

<template>
    <Head title="AI Assistant" />

    <div class="flex h-full flex-col gap-4 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold tracking-tight">AI Assistant</h2>
                <p class="text-sm text-muted-foreground">
                    Chat with a local agent backed by your configured services.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="sending || messages.length === 0"
                    @click="newConversation"
                >
                    New conversation
                </Button>
            </div>
        </div>

        <Card class="flex flex-1 flex-col">
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <Bot class="size-4 text-muted-foreground" />
                    Assistant
                    <Badge
                        v-if="conversationId"
                        variant="outline"
                        class="ml-auto font-mono text-xs"
                    >
                        {{ conversationId.slice(0, 8) }}
                    </Badge>
                </CardTitle>
            </CardHeader>
            <CardContent class="flex flex-1 flex-col gap-4 overflow-hidden">
                <div
                    ref="scroll"
                    class="min-h-0 flex-1 space-y-4 overflow-y-auto rounded-md border bg-muted/20 p-4"
                >
                    <div
                        v-if="messages.length === 0"
                        class="flex h-full flex-col items-center justify-center text-center text-muted-foreground"
                    >
                        <Bot class="mb-2 size-8 opacity-50" />
                        <p class="text-sm">
                            Ask about your library, request actions, or check
                            service health.
                        </p>
                    </div>

                    <div
                        v-for="m in messages"
                        :key="m.ts"
                        class="flex gap-3"
                        :class="
                            m.role === 'user' ? 'justify-end' : 'justify-start'
                        "
                    >
                        <div
                            class="flex max-w-[80%] gap-2 rounded-lg px-4 py-3 text-sm"
                            :class="
                                m.role === 'user'
                                    ? 'flex-row-reverse bg-primary text-primary-foreground'
                                    : 'border bg-background'
                            "
                        >
                            <component
                                :is="m.role === 'user' ? UserIcon : Bot"
                                class="size-4 shrink-0 opacity-70"
                            />
                            <div class="break-words whitespace-pre-wrap">
                                {{ m.text }}
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="sending"
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <Bot class="size-4 animate-pulse" />
                        Thinking…
                    </div>
                </div>

                <div
                    v-if="error"
                    class="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                    {{ error }}
                </div>

                <form
                    class="flex items-center gap-2"
                    @submit.prevent="sendMessage"
                >
                    <Input
                        v-model="input"
                        placeholder="Ask something…"
                        :disabled="sending"
                        class="flex-1"
                    />
                    <Button type="submit" :disabled="sending || !input.trim()">
                        <Send class="size-4" />
                    </Button>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
