<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import ServerWatcherController from '@/actions/App/Http/Controllers/Admin/ServerWatcherController';
import InputError from '@/components/InputError.vue';
import { Field, SettingsRow } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useConfirm } from '@/composables/useConfirm';
import type { WatcherState } from './types';

/**
 * The outside watcher: an Uptime Kuma push monitor this platform pings every
 * minute. The saved URL carries the monitor's token, so the page only ever
 * receives its last four characters.
 */
defineProps<{
    watcher: WatcherState;
}>();

const { confirm } = useConfirm();

async function clear(): Promise<void> {
    const confirmed = await confirm({
        title: 'Remove the push URL?',
        description:
            'Nothing outside this platform will notice when it stops working.',
        confirmLabel: 'Remove',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    router.put(
        ServerWatcherController.url(),
        { clear: true },
        {
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <section
        class="space-y-4 rounded-xl border border-border bg-card p-5"
        data-watcher-settings
    >
        <div>
            <h2 class="text-[15px] font-semibold">Outside watcher</h2>
            <p class="mt-1 max-w-[640px] text-[12.5px] text-muted-foreground">
                Every minute this platform pings an Uptime Kuma push monitor, so
                something outside it notices when it goes quiet. Use an https
                URL; http is fine only on a private network (a tailnet or your
                LAN), because the URL carries the monitor's token.
            </p>
        </div>

        <Form
            v-bind="ServerWatcherController.form()"
            reset-on-success
            :options="{ preserveScroll: true }"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <SettingsRow>
                <template #label>
                    <Field
                        label="Push URL"
                        hint="Leave empty to keep the saved URL."
                    />
                </template>
                <div class="min-w-0 space-y-2">
                    <Input
                        name="push_url"
                        type="url"
                        autocomplete="off"
                        placeholder="https://kuma.example/api/push/…"
                        aria-label="Push URL"
                        data-watcher-push-url
                    />
                    <p
                        class="text-[12px] text-muted-foreground"
                        data-watcher-hint
                    >
                        <template v-if="watcher.push_url_set">
                            Saved URL ends in
                            <span class="font-mono-tabular text-foreground">{{
                                watcher.push_url_hint
                            }}</span>
                        </template>
                        <template v-else>No push URL saved.</template>
                    </p>
                    <InputError :message="errors.push_url" />
                </div>
            </SettingsRow>

            <div class="flex flex-wrap justify-end gap-2">
                <Button
                    v-if="watcher.push_url_set"
                    type="button"
                    variant="outline"
                    size="sm"
                    data-watcher-clear
                    @click="clear"
                >
                    Remove URL
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="processing"
                    data-watcher-save
                >
                    Save
                </Button>
            </div>
        </Form>
    </section>
</template>
