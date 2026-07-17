<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Eye, Search } from '@lucide/vue';
import { ref } from 'vue';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import AiConversationController from '@/actions/App/Http/Controllers/Admin/AiConversationController';
import { dashboard } from '@/routes';

interface ConversationRow {
    id: string;
    title: string;
    archived_at: string | null;
    updated_at: string;
    created_at: string;
    message_count: number;
    user: { id: number; name: string; email: string } | null;
}

interface PageMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const props = defineProps<{
    conversations: {
        data: ConversationRow[];
        meta: PageMeta;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        state: 'active' | 'archived' | 'all';
        user_id: number | null;
        q: string;
    };
    states: Array<'active' | 'archived' | 'all'>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            {
                title: 'AI Conversations',
                href: AiConversationController.index.url(),
            },
        ],
    },
});

const search = ref(props.filters.q);

function visit(state: 'active' | 'archived' | 'all'): void {
    router.get(
        AiConversationController.index.url(),
        {
            state,
            q: search.value || undefined,
            user_id: props.filters.user_id ?? undefined,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function applySearch(): void {
    router.get(
        AiConversationController.index.url(),
        {
            state: props.filters.state,
            q: search.value || undefined,
            user_id: props.filters.user_id ?? undefined,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function clearSearch(): void {
    search.value = '';
    applySearch();
}

function archive(id: string): void {
    router.post(
        AiConversationController.archive.url(id),
        {},
        { preserveScroll: true },
    );
}

function unarchive(id: string): void {
    router.post(
        AiConversationController.unarchive.url(id),
        {},
        { preserveScroll: true },
    );
}

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString([], {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}
</script>

<template>
    <Head title="AI Conversations" />

    <div class="flex flex-col gap-4 p-5">
        <div>
            <div class="mb-1.5 text-[13px] text-muted-foreground">
                Admin <span class="text-fg-subtle">/</span> AI Conversations
            </div>
            <h1 class="text-[22px] leading-tight font-semibold tracking-tight">
                AI conversations
            </h1>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                Every chat across all users. Archive to hide from the user's
                picker without losing the transcript; delete to remove
                permanently.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div
                class="flex items-center gap-0.5 rounded-md border border-border bg-bg-elev p-0.5"
            >
                <button
                    v-for="state in states"
                    :key="state"
                    type="button"
                    :class="
                        cn(
                            'inline-flex h-7 items-center rounded px-2.5 text-xs font-medium capitalize transition-colors',
                            filters.state === state
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                        )
                    "
                    @click="visit(state)"
                >
                    {{ state }}
                </button>
            </div>

            <form class="flex items-center gap-2" @submit.prevent="applySearch">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        placeholder="Search title…"
                        class="h-8 w-64 pl-7 text-sm"
                    />
                </div>
                <Button
                    v-if="search"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-8 text-xs"
                    @click="clearSearch"
                >
                    Clear
                </Button>
            </form>

            <span class="ml-auto text-[12px] text-muted-foreground">
                {{ conversations.meta.total }} total · page
                {{ conversations.meta.current_page }} of
                {{ conversations.meta.last_page }}
            </span>
        </div>

        <div class="rounded-xl border border-border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Title</TableHead>
                        <TableHead class="w-44">User</TableHead>
                        <TableHead class="w-20 text-right">Messages</TableHead>
                        <TableHead class="w-44">Updated</TableHead>
                        <TableHead class="w-24">Status</TableHead>
                        <TableHead class="w-44 text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="row in conversations.data" :key="row.id">
                        <TableCell>
                            <a
                                :href="
                                    AiConversationController.show.url(row.id)
                                "
                                class="line-clamp-1 max-w-[420px] font-medium text-foreground hover:underline"
                            >
                                {{ row.title }}
                            </a>
                            <div
                                class="font-mono-tabular text-[10.5px] text-muted-foreground"
                            >
                                {{ row.id.slice(0, 12) }}…
                            </div>
                        </TableCell>
                        <TableCell class="text-[12.5px]">
                            <span v-if="row.user">
                                {{ row.user.name }}
                                <div
                                    class="text-[10.5px] text-muted-foreground"
                                >
                                    {{ row.user.email }}
                                </div>
                            </span>
                            <span v-else class="text-muted-foreground">
                                —
                            </span>
                        </TableCell>
                        <TableCell
                            class="font-mono-tabular text-right text-[12px]"
                        >
                            {{ row.message_count }}
                        </TableCell>
                        <TableCell class="text-[12px] text-muted-foreground">
                            {{ formatDate(row.updated_at) }}
                        </TableCell>
                        <TableCell>
                            <Pill
                                v-if="row.archived_at"
                                variant="warn"
                                class="text-[10px]"
                            >
                                archived
                            </Pill>
                            <Pill v-else variant="ok" class="text-[10px]">
                                active
                            </Pill>
                        </TableCell>
                        <TableCell class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    as="a"
                                    :href="
                                        AiConversationController.show.url(
                                            row.id,
                                        )
                                    "
                                    size="sm"
                                    variant="ghost"
                                    class="h-7 gap-1 text-xs"
                                >
                                    <Eye class="size-3.5" />
                                    View
                                </Button>
                                <Button
                                    v-if="!row.archived_at"
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    class="h-7 gap-1 text-xs"
                                    @click="archive(row.id)"
                                >
                                    <Archive class="size-3.5" />
                                    Archive
                                </Button>
                                <Button
                                    v-else
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    class="h-7 gap-1 text-xs"
                                    @click="unarchive(row.id)"
                                >
                                    <ArchiveRestore class="size-3.5" />
                                    Unarchive
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                    <TableEmpty
                        v-if="conversations.data.length === 0"
                        :colspan="6"
                    >
                        No conversations match this filter.
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>

        <div
            v-if="conversations.meta.last_page > 1"
            class="flex items-center justify-end gap-2"
        >
            <Button
                v-for="(link, i) in conversations.links"
                :key="i"
                as="a"
                :href="link.url ?? '#'"
                size="sm"
                :variant="link.active ? 'default' : 'outline'"
                :disabled="!link.url"
                class="h-7 min-w-7 px-2 text-xs"
            >
                <span v-html="link.label" />
            </Button>
        </div>
    </div>
</template>
