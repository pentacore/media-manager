<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Gauge } from '@lucide/vue';
import { computed, ref } from 'vue';
import QueueController from '@/actions/App/Http/Controllers/Sabnzbd/QueueController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    /** SABnzbd `speedlimit`: percent of the configured maximum. */
    percent: string | null;
    /** SABnzbd `speedlimit_abs`: bytes per second, or empty. */
    absolute: string | null;
}>();

const PRESETS = [
    { key: 'none', value: '', label: 'No limit' },
    { key: '25', value: '25', label: '25%' },
    { key: '50', value: '50', label: '50%' },
    { key: '75', value: '75', label: '75%' },
] as const;

const customOpen = ref(false);
const customValue = ref('');
const customError = ref<string | null>(null);
const saving = ref(false);

function formatRate(bytesPerSecond: number): string {
    if (bytesPerSecond >= 1024 * 1024) {
        return `${(bytesPerSecond / (1024 * 1024)).toFixed(1)} MB/s`;
    }

    return `${Math.round(bytesPerSecond / 1024)} KB/s`;
}

const currentLabel = computed(() => {
    const percent = Number(props.percent ?? '');
    const absolute = Number(props.absolute ?? '');
    const hasPercent = Number.isFinite(percent) && percent > 0 && percent < 100;
    const hasAbsolute = Number.isFinite(absolute) && absolute > 0;

    if (hasPercent && hasAbsolute) {
        return `${percent}% · ${formatRate(absolute)}`;
    }

    if (hasAbsolute) {
        return formatRate(absolute);
    }

    return hasPercent ? `${percent}%` : 'No limit';
});

function apply(value: string): void {
    saving.value = true;
    customError.value = null;

    router.post(
        QueueController.setSpeedLimit.url(),
        { value },
        {
            preserveScroll: true,
            onSuccess: () => {
                customOpen.value = false;
            },
            onError: (errors) => {
                customError.value =
                    errors.value ??
                    'Use 1–100 (percent) or a rate like 500K or 5M.';
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

function openCustom(): void {
    customValue.value = '';
    customError.value = null;
    customOpen.value = true;
}
</script>

<template>
    <div class="flex items-center" data-speed-limit>
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    size="sm"
                    variant="outline"
                    class="h-7 gap-1.5 text-xs"
                    :disabled="saving"
                    data-speed-limit-trigger
                >
                    <Gauge class="size-3.5" />
                    <span data-speed-limit-current>{{ currentLabel }}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-44">
                <DropdownMenuLabel>Speed limit</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    v-for="preset in PRESETS"
                    :key="preset.key"
                    :data-speed-limit-preset="preset.key"
                    @select="apply(preset.value)"
                >
                    {{ preset.label }}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem data-speed-limit-custom @select="openCustom">
                    Custom…
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <Dialog v-model:open="customOpen">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Custom speed limit</DialogTitle>
                    <DialogDescription>
                        A percentage of SABnzbd's maximum speed (1–100), or a
                        rate such as 500K or 5M.
                    </DialogDescription>
                </DialogHeader>
                <form
                    class="space-y-2"
                    @submit.prevent="apply(customValue.trim())"
                >
                    <Label for="speed-limit-value">Limit</Label>
                    <Input
                        id="speed-limit-value"
                        v-model="customValue"
                        placeholder="5M"
                        autocomplete="off"
                        data-speed-limit-input
                    />
                    <p
                        v-if="customError"
                        class="text-xs text-destructive"
                        data-speed-limit-error
                    >
                        {{ customError }}
                    </p>
                    <DialogFooter>
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="saving || customValue.trim() === ''"
                            data-speed-limit-save
                        >
                            Set limit
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
