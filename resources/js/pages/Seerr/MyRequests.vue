<script setup lang="ts">
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { AlertCircle } from '@lucide/vue';
import { ref } from 'vue';
import MyRequestController from '@/actions/App/Http/Controllers/Media/MyRequestController';
import { Poster, StatusPill } from '@/components/mm';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useDateTime } from '@/composables/useDateTime';
import { tmdbPosterUrl } from '@/lib/tmdb';
import { dashboard } from '@/routes';

interface MyRequest {
    id: number;
    media_type: string;
    title: string;
    poster_path: string | null;
    status:
        | 'pending'
        | 'approved'
        | 'declined'
        | 'failed'
        | 'completed'
        | 'available';
    requested_at: string | null;
    can_cancel: boolean;
}

const props = defineProps<{
    seerr: { connected: boolean };
    filters: { page: number };
    requests?: {
        linked: boolean;
        results: MyRequest[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
        error: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard().url },
            { title: 'My requests', href: MyRequestController.index.url() },
        ],
    },
});

const { formatDate } = useDateTime();
const cancelling = ref<MyRequest | null>(null);
const submitting = ref(false);

function confirmCancel(): void {
    if (!cancelling.value || submitting.value) {
        return;
    }

    submitting.value = true;
    router.delete(MyRequestController.destroy.url(cancelling.value.id), {
        preserveScroll: true,
        onFinish: () => {
            submitting.value = false;
            cancelling.value = null;
        },
    });
}
</script>

<template>
    <Head title="My requests" />

    <div class="flex flex-col gap-4 p-5">
        <div>
            <h1 class="text-[22px] leading-tight font-semibold tracking-tight">
                My requests
            </h1>
            <p class="mt-1 text-[13px] text-muted-foreground">
                What you asked for, and where it is.
            </p>
        </div>

        <div
            v-if="!props.seerr.connected"
            class="rounded-xl border border-border bg-card p-6 text-[13px] text-muted-foreground"
        >
            No Seerr connection is configured.
        </div>

        <Deferred v-else data="requests">
            <template #fallback>
                <div class="space-y-2">
                    <Skeleton
                        v-for="n in 4"
                        :key="n"
                        class="h-16 w-full rounded-xl"
                    />
                </div>
            </template>

            <Alert v-if="props.requests?.error" variant="destructive">
                <AlertCircle class="size-4" />
                <AlertTitle>Seerr unavailable</AlertTitle>
                <AlertDescription>{{ props.requests.error }}</AlertDescription>
            </Alert>
            <div
                v-else-if="props.requests && !props.requests.linked"
                class="rounded-xl border border-border bg-card p-6 text-[13px] text-muted-foreground"
                data-my-requests-unlinked
            >
                No Seerr account is linked to you — ask an admin.
            </div>
            <div
                v-else-if="
                    props.requests && props.requests.results.length === 0
                "
                class="rounded-xl border border-border bg-card p-6 text-[13px] text-muted-foreground"
            >
                You have not requested anything yet.
            </div>
            <div
                v-else-if="props.requests"
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <div
                    v-for="(request, i) in props.requests.results"
                    :key="request.id"
                    :class="[
                        'flex items-center gap-3.5 px-4 py-3',
                        i > 0 && 'border-t border-border',
                    ]"
                    :data-my-request="request.id"
                >
                    <Poster
                        :hint="request.title"
                        size="sm"
                        :src="tmdbPosterUrl(request.poster_path)"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="text-[13px] font-medium">
                            {{ request.title }}
                        </div>
                        <div class="text-[11.5px] text-muted-foreground">
                            {{ request.media_type === 'tv' ? 'TV' : 'Movie'
                            }}<template v-if="request.requested_at">
                                ·
                                {{ formatDate(request.requested_at) }}</template
                            >
                        </div>
                    </div>
                    <StatusPill :status="request.status" />
                    <Button
                        v-if="request.can_cancel"
                        variant="outline"
                        size="sm"
                        class="h-7 text-xs"
                        :data-cancel-request="request.id"
                        @click="cancelling = request"
                    >
                        Cancel
                    </Button>
                </div>
            </div>

            <div
                v-if="props.requests && props.requests.meta.last_page > 1"
                class="flex items-center justify-between text-[12px]"
            >
                <Link
                    v-if="props.requests.meta.current_page > 1"
                    :href="
                        MyRequestController.index.url({
                            query: {
                                page: props.requests.meta.current_page - 1,
                            },
                        })
                    "
                    preserve-scroll
                    >Previous</Link
                >
                <span class="text-muted-foreground"
                    >Page {{ props.requests.meta.current_page }} of
                    {{ props.requests.meta.last_page }}</span
                >
                <Link
                    v-if="
                        props.requests.meta.current_page <
                        props.requests.meta.last_page
                    "
                    :href="
                        MyRequestController.index.url({
                            query: {
                                page: props.requests.meta.current_page + 1,
                            },
                        })
                    "
                    preserve-scroll
                    >Next</Link
                >
            </div>
        </Deferred>
    </div>

    <Dialog
        :open="cancelling !== null"
        @update:open="
            (open) => {
                if (!open) cancelling = null;
            }
        "
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle
                    >Cancel your request for
                    {{ cancelling?.title }}?</DialogTitle
                >
                <DialogDescription
                    >It is still waiting for approval, so nothing has been
                    downloaded yet.</DialogDescription
                >
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="cancelling = null"
                    >Keep it</Button
                >
                <Button
                    variant="destructive"
                    :disabled="submitting"
                    data-cancel-confirm
                    @click="confirmCancel"
                >
                    Cancel request
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
