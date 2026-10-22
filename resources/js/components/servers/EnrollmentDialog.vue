<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Check, ClipboardCopy } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { TimeStamp } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { copyToClipboard } from '@/lib/clipboard';
import type { EnrollmentInstructions } from './types';

/**
 * Shows the one-time enrollment token and install steps. The token arrives
 * only in the `enrollment` flash of the visit that created it, so a reload or
 * a later visit never shows it again.
 */
const props = defineProps<{
    serverId?: number;
    serverName?: string;
}>();

type CopyTarget = 'token' | 'debian';

const instructions = ref<EnrollmentInstructions | null>(null);
const copied = ref<CopyTarget | null>(null);
let copiedTimer: ReturnType<typeof setTimeout> | null = null;

function enrollmentFrom(flash: unknown): EnrollmentInstructions | null {
    if (typeof flash !== 'object' || flash === null) {
        return null;
    }

    const enrollment = (flash as { enrollment?: unknown }).enrollment;

    if (
        typeof enrollment !== 'object' ||
        enrollment === null ||
        typeof (enrollment as EnrollmentInstructions).token !== 'string'
    ) {
        return null;
    }

    const candidate = enrollment as EnrollmentInstructions;

    if (
        props.serverId !== undefined &&
        candidate.server_id !== props.serverId
    ) {
        return null;
    }

    return candidate;
}

function show(flash: unknown): void {
    const next = enrollmentFrom(flash);

    if (next !== null) {
        copied.value = null;
        instructions.value = next;
    }
}

function close(open: boolean): void {
    if (!open) {
        instructions.value = null;
    }
}

async function copy(target: CopyTarget): Promise<void> {
    if (!instructions.value) {
        return;
    }

    const text =
        target === 'token'
            ? instructions.value.token
            : instructions.value.debian_command;

    if (!(await copyToClipboard(text))) {
        return;
    }

    copied.value = target;

    if (copiedTimer) {
        clearTimeout(copiedTimer);
    }

    copiedTimer = setTimeout(() => {
        copied.value = null;
        copiedTimer = null;
    }, 2000);
}

// Inertia fires `flash` after the redirected-to page has mounted; reading the
// page's flash on mount as well keeps the dialog working if that order ever
// changes. Inertia keeps flash out of history entries, so Back never shows it.
const page = usePage();
let stopFlashListener: (() => void) | null = null;

onMounted(() => {
    show(page.flash);

    stopFlashListener = router.on('flash', (event) => {
        show((event as CustomEvent).detail?.flash);
    });
});

onBeforeUnmount(() => {
    stopFlashListener?.();
    stopFlashListener = null;

    if (copiedTimer) {
        clearTimeout(copiedTimer);
        copiedTimer = null;
    }
});
</script>

<template>
    <Dialog :open="instructions !== null" @update:open="close">
        <DialogContent
            v-if="instructions"
            class="sm:max-w-xl"
            data-enrollment-dialog
        >
            <DialogHeader>
                <DialogTitle>
                    Install the agent{{ serverName ? ` on ${serverName}` : '' }}
                </DialogTitle>
                <DialogDescription>
                    This token is shown once. It works for one enrollment and
                    expires
                    <TimeStamp
                        :iso="instructions.expires_at"
                        mode="datetime"
                    />.
                </DialogDescription>
            </DialogHeader>

            <div class="min-w-0 space-y-5 text-[13px]">
                <section class="space-y-1.5">
                    <h3
                        class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >
                        Enrollment token
                    </h3>
                    <div class="flex items-start gap-2">
                        <code
                            class="font-mono-tabular min-w-0 flex-1 rounded-md border border-border bg-bg-elev px-2.5 py-1.5 text-[12.5px] break-all"
                            data-enrollment-token
                            >{{ instructions.token }}</code
                        >
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            class="shrink-0"
                            data-enrollment-copy="token"
                            :aria-label="
                                copied === 'token'
                                    ? 'Token copied'
                                    : 'Copy token'
                            "
                            @click="copy('token')"
                        >
                            <Check v-if="copied === 'token'" class="size-4" />
                            <ClipboardCopy v-else class="size-4" />
                        </Button>
                    </div>
                </section>

                <section class="space-y-1.5">
                    <h3
                        class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >
                        Debian / Ubuntu
                    </h3>
                    <p class="text-muted-foreground">
                        Run this on the server as a user with sudo:
                    </p>
                    <div class="flex items-start gap-2">
                        <pre
                            class="font-mono-tabular min-w-0 flex-1 rounded-md border border-border bg-bg-elev px-2.5 py-1.5 text-[12px] break-all whitespace-pre-wrap"
                            data-enrollment-debian-command
                            >{{ instructions.debian_command }}</pre>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            class="shrink-0"
                            data-enrollment-copy="debian"
                            :aria-label="
                                copied === 'debian'
                                    ? 'Command copied'
                                    : 'Copy install command'
                            "
                            @click="copy('debian')"
                        >
                            <Check v-if="copied === 'debian'" class="size-4" />
                            <ClipboardCopy v-else class="size-4" />
                        </Button>
                    </div>
                </section>

                <section class="space-y-1.5">
                    <h3
                        class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >
                        Unraid
                    </h3>
                    <ol class="list-decimal space-y-1.5 pl-5">
                        <li>
                            In Plugins → Install Plugin, paste this URL:
                            <code
                                class="font-mono-tabular mt-1 block rounded-md border border-border bg-bg-elev px-2.5 py-1.5 text-[12px] break-all"
                                data-enrollment-unraid-url
                                >{{ instructions.unraid_plugin_url }}</code
                            >
                        </li>
                        <li>
                            Open the Lookout plugin's settings page and paste
                            the platform URL
                            <code
                                class="font-mono-tabular text-[12px] break-all"
                                data-enrollment-platform-url
                                >{{ instructions.platform_url }}</code
                            >
                            and the token above.
                        </li>
                    </ol>
                </section>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    data-enrollment-done
                    @click="close(false)"
                >
                    Done
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
