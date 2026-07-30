<script setup lang="ts">
import { Languages, RefreshCcw, Trash2, WandSparkles } from '@lucide/vue';
import { Button } from '@/components/ui/button';

export interface SubtitleTrack {
    fingerprint: string;
    language: string;
    display_name: string;
    kind: 'embedded' | 'external';
    forced: boolean;
    hearing_impaired: boolean;
}

defineProps<{
    tracks: SubtitleTrack[];
    capabilities: Record<string, boolean> | null;
    canOperate: boolean;
}>();

const emit = defineEmits<{
    operate: [
        payload: {
            operation:
                | 'delete_subtitle'
                | 'sync_subtitle'
                | 'translate_subtitle'
                | 'modify_subtitle';
            track: SubtitleTrack;
            tool_action?: string;
        },
    ];
}>();
</script>

<template>
    <div class="space-y-2">
        <p v-if="tracks.length === 0" class="text-sm text-muted-foreground">
            No subtitle tracks are currently attached.
        </p>
        <div
            v-for="(track, index) in tracks"
            v-else
            :key="track.fingerprint"
            class="rounded-lg border border-border p-3"
        >
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="text-sm font-medium">{{ track.display_name }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ track.language.toUpperCase() }} · {{ track.kind }}
                        <template v-if="track.forced"> · forced</template>
                        <template v-if="track.hearing_impaired"> · HI</template>
                    </p>
                </div>
            </div>
            <div
                v-if="canOperate && track.kind === 'external'"
                class="mt-3 flex flex-wrap gap-2"
            >
                <Button
                    size="sm"
                    variant="outline"
                    :data-test="`subtitle-track-${index}-sync`"
                    :disabled="capabilities?.sync === false"
                    @click="
                        emit('operate', {
                            operation: 'sync_subtitle',
                            track,
                        })
                    "
                >
                    <RefreshCcw />
                    Sync
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    :data-test="`subtitle-track-${index}-translate`"
                    :disabled="capabilities?.translate === false"
                    @click="
                        emit('operate', {
                            operation: 'translate_subtitle',
                            track,
                        })
                    "
                >
                    <Languages />
                    Translate
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    :data-test="`subtitle-track-${index}-remove-hi`"
                    :disabled="capabilities?.sync === false"
                    @click="
                        emit('operate', {
                            operation: 'modify_subtitle',
                            track,
                            tool_action: 'remove_HI',
                        })
                    "
                >
                    <WandSparkles />
                    Remove HI tags
                </Button>
                <Button
                    size="sm"
                    variant="destructive"
                    :data-test="`subtitle-track-${index}-delete`"
                    :disabled="capabilities?.delete === false"
                    @click="
                        emit('operate', {
                            operation: 'delete_subtitle',
                            track,
                        })
                    "
                >
                    <Trash2 />
                    Delete
                </Button>
            </div>
        </div>
    </div>
</template>
