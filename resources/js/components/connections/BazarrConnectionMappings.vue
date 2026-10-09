<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { NOT_CONNECTED_VALUE } from './bazarrMappings';
import type { ArrConnectionOption } from './types';

const props = defineProps<{
    arrConnections: ArrConnectionOption[];
    errors: Record<string, string>;
}>();

const sonarrConnectionId = defineModel<string>('sonarrConnectionId', {
    required: true,
});
const radarrConnectionId = defineModel<string>('radarrConnectionId', {
    required: true,
});

const sonarrConnections = computed(() =>
    props.arrConnections.filter((connection) => connection.type === 'sonarr'),
);
const radarrConnections = computed(() =>
    props.arrConnections.filter((connection) => connection.type === 'radarr'),
);
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2" data-bazarr-mappings>
        <div class="space-y-2">
            <Label for="sonarr_connection_id">Sonarr connection</Label>
            <Select name="sonarr_connection_id" v-model="sonarrConnectionId">
                <SelectTrigger id="sonarr_connection_id" class="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        :value="NOT_CONNECTED_VALUE"
                        aria-label="No Sonarr connection"
                        >Not connected</SelectItem
                    >
                    <SelectItem
                        v-for="connection in sonarrConnections"
                        :key="connection.id"
                        :value="String(connection.id)"
                        :aria-label="`Use ${connection.name} as Sonarr connection`"
                    >
                        {{ connection.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <InputError :message="errors.sonarr_connection_id" />
        </div>

        <div class="space-y-2">
            <Label for="radarr_connection_id">Radarr connection</Label>
            <Select name="radarr_connection_id" v-model="radarrConnectionId">
                <SelectTrigger id="radarr_connection_id" class="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        :value="NOT_CONNECTED_VALUE"
                        aria-label="No Radarr connection"
                        >Not connected</SelectItem
                    >
                    <SelectItem
                        v-for="connection in radarrConnections"
                        :key="connection.id"
                        :value="String(connection.id)"
                        :aria-label="`Use ${connection.name} as Radarr connection`"
                    >
                        {{ connection.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <InputError :message="errors.radarr_connection_id" />
        </div>
    </div>
</template>
