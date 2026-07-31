<script setup lang="ts">
import { Download } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { SubtitleCandidateResource } from '@/typefinder/resources/SubtitleCandidateResource';

defineProps<{
    candidates: SubtitleCandidateResource[];
    disabled?: boolean;
}>();

const emit = defineEmits<{
    request: [candidate: SubtitleCandidateResource];
}>();
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-border">
        <div
            v-if="candidates.length === 0"
            class="p-4 text-sm text-muted-foreground"
        >
            No matching subtitle candidates were returned.
        </div>
        <div
            v-for="(candidate, index) in candidates"
            v-else
            :key="candidate.fingerprint"
            class="flex flex-col gap-3 border-b border-border p-3 last:border-b-0 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{ candidate.provider }}</span>
                    <span
                        class="rounded-full bg-muted px-2 py-0.5 text-xs uppercase"
                    >
                        {{ candidate.language }}
                    </span>
                    <span
                        v-if="candidate.score !== null"
                        class="text-xs text-muted-foreground"
                    >
                        Score {{ candidate.score }}
                    </span>
                </div>
                <p
                    v-if="candidate.release_info.length"
                    class="mt-1 truncate text-xs text-muted-foreground"
                >
                    {{ candidate.release_info.join(' · ') }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    <template v-if="candidate.forced">Forced · </template>
                    <template v-if="candidate.hearing_impaired">
                        Hearing impaired ·
                    </template>
                    {{ candidate.uploader ?? 'Unknown uploader' }}
                </p>
            </div>
            <Button
                size="sm"
                :disabled="disabled"
                :data-test="`candidate-request-${index}`"
                @click="emit('request', candidate)"
            >
                <Download />
                Request
            </Button>
        </div>
    </div>
</template>
