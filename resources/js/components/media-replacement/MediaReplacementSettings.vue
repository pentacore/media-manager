<script setup lang="ts">
import { computed, reactive, toRaw } from 'vue';
import { Field } from '@/components/mm';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import SubtitleRuleEditor from './SubtitleRuleEditor.vue';
import type { SubtitleRule } from './SubtitleRuleEditor.vue';

interface ScopeGuidance {
    notes: string;
    rules: SubtitleRule[];
}

type Scope = 'anime' | 'tv' | 'movie';
type SonarrRootScope = Exclude<Scope, 'movie'>;

interface SonarrRootFolderAssignment {
    service_connection_id: number;
    root_folder_id: number;
    path: string;
    scope: SonarrRootScope | null;
}

export interface SonarrRootFolderOption {
    service_connection_id: number;
    connection_name: string;
    root_folder_id: number;
    path: string;
}

export interface MediaReplacementConfiguration {
    automatic_selection_enabled: boolean;
    automatic_selection_threshold: number;
    global_languages: string[];
    scoped_languages: Record<Scope, string[] | null>;
    season_pack_policy: string;
    sonarr_root_folders: SonarrRootFolderAssignment[];
    guidance: Record<Scope, ScopeGuidance>;
}

const props = defineProps<{
    configuration: MediaReplacementConfiguration;
    seasonPackPolicies: Array<{ value: string; label: string }>;
    subtitleRuleStrengths: Array<{ value: string; label: string }>;
    conditionFields: Array<{ value: string; label: string }>;
    sonarrRootFolders: SonarrRootFolderOption[];
    errors: Record<string, string>;
}>();

const state = reactive<MediaReplacementConfiguration>(
    structuredClone(toRaw(props.configuration)),
);

for (const rootFolder of props.sonarrRootFolders) {
    const configured = state.sonarr_root_folders.find(
        (candidate) =>
            candidate.service_connection_id ===
                rootFolder.service_connection_id &&
            candidate.root_folder_id === rootFolder.root_folder_id,
    );

    if (configured) {
        configured.path = rootFolder.path;
    } else {
        state.sonarr_root_folders.push({
            service_connection_id: rootFolder.service_connection_id,
            root_folder_id: rootFolder.root_folder_id,
            path: rootFolder.path,
            scope: null,
        });
    }
}

const sonarrRootFolderRows = computed(() =>
    state.sonarr_root_folders.map((assignment) => {
        const imported = props.sonarrRootFolders.find(
            (candidate) =>
                candidate.service_connection_id ===
                    assignment.service_connection_id &&
                candidate.root_folder_id === assignment.root_folder_id,
        );

        return {
            assignment,
            connectionName:
                imported?.connection_name ??
                `Sonarr connection ${assignment.service_connection_id}`,
        };
    }),
);

const scopes: Array<{ key: Scope; label: string }> = [
    { key: 'anime', label: 'Anime' },
    { key: 'tv', label: 'TV' },
    { key: 'movie', label: 'Movies' },
];

function languagesToText(list: string[]): string {
    return list.join(', ');
}

function textToLanguages(value: string): string[] {
    return value
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);
}

function setGlobalLanguages(value: string): void {
    state.global_languages = textToLanguages(value);
}

function hasScopeOverride(scope: Scope): boolean {
    return state.scoped_languages[scope] !== null;
}

function toggleScopeOverride(scope: Scope, enabled: boolean): void {
    state.scoped_languages[scope] = enabled ? [] : null;
}

function scopeLanguagesText(scope: Scope): string {
    return languagesToText(state.scoped_languages[scope] ?? []);
}

function setScopeLanguages(scope: Scope, value: string): void {
    state.scoped_languages[scope] = textToLanguages(value);
}

function rootFolderInputName(assignment: SonarrRootFolderAssignment): string {
    return `sonarr_root_scope_${assignment.service_connection_id}_${assignment.root_folder_id}`;
}

function setRootFolderScope(
    assignment: SonarrRootFolderAssignment,
    value: string,
): void {
    assignment.scope = value === 'anime' || value === 'tv' ? value : null;
}
</script>

<template>
    <div class="space-y-5">
        <div>
            <h2 class="text-[15px] font-semibold tracking-tight">
                Media replacement
            </h2>
            <p class="mt-1 text-[13px] text-muted-foreground">
                Let Media Advisor replace an imported episode or movie whose
                subtitles are missing or wrong, using deterministic release
                evidence you configure here.
            </p>
        </div>

        <div
            class="grid items-start gap-6"
            style="grid-template-columns: 200px 1fr"
        >
            <Field
                label="Automatic selection"
                hint="When enabled, a unique candidate above the confidence threshold may be queued without asking you to choose. The Action Rule still governs execution."
            >
                <span />
            </Field>
            <label class="flex items-center gap-2 text-sm">
                <input
                    v-model="state.automatic_selection_enabled"
                    type="checkbox"
                />
                Enable automatic candidate selection
            </label>
        </div>

        <div
            class="grid items-start gap-6"
            style="grid-template-columns: 200px 1fr"
        >
            <Field
                label="Confidence threshold"
                hint="Minimum subtitle confidence (0–100) required before a candidate can be selected automatically."
            >
                <span />
            </Field>
            <Input
                v-model.number="state.automatic_selection_threshold"
                type="number"
                min="0"
                max="100"
                class="h-8 max-w-[120px] text-sm"
            />
        </div>

        <div
            class="grid items-start gap-6"
            style="grid-template-columns: 200px 1fr"
        >
            <Field
                label="Preferred subtitle languages"
                hint="Global comma-separated list of required subtitle languages. Scopes inherit this unless overridden."
            >
                <span />
            </Field>
            <Input
                :model-value="languagesToText(state.global_languages)"
                type="text"
                placeholder="English, Swedish"
                class="h-8 max-w-[320px] text-sm"
                @update:model-value="setGlobalLanguages(String($event))"
            />
        </div>

        <div
            class="grid items-start gap-6"
            style="grid-template-columns: 200px 1fr"
        >
            <Field
                label="Season-pack policy"
                hint="Controls whether a season pack may replace an individual episode, and whether it forces approval."
            >
                <span />
            </Field>
            <Select v-model="state.season_pack_policy">
                <SelectTrigger class="h-8 max-w-[320px] text-sm">
                    <SelectValue placeholder="Select a policy" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="policy in seasonPackPolicies"
                        :key="policy.value"
                        :value="policy.value"
                    >
                        {{ policy.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <Separator />

        <div class="space-y-3">
            <div>
                <h3 class="text-sm font-semibold">Sonarr library types</h3>
                <p class="mt-1 text-xs text-muted-foreground">
                    Classify each imported Sonarr root folder by its content.
                    Sonarr's series type describes episode numbering and does
                    not reliably identify anime.
                </p>
            </div>

            <div
                v-if="sonarrRootFolderRows.length === 0"
                class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground"
            >
                No active Sonarr root folders could be imported.
            </div>

            <div v-else class="space-y-2">
                <div
                    v-for="row in sonarrRootFolderRows"
                    :key="`${row.assignment.service_connection_id}:${row.assignment.root_folder_id}`"
                    class="grid gap-3 rounded-md border border-border px-3 py-2.5 sm:grid-cols-[minmax(0,1fr)_180px] sm:items-center"
                >
                    <div class="min-w-0">
                        <div class="text-xs font-medium">
                            {{ row.connectionName }}
                        </div>
                        <div
                            class="truncate font-mono text-xs text-muted-foreground"
                            :title="row.assignment.path"
                        >
                            {{ row.assignment.path }}
                        </div>
                    </div>

                    <select
                        :name="rootFolderInputName(row.assignment)"
                        :value="row.assignment.scope ?? 'unassigned'"
                        class="h-8 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                        @change="
                            setRootFolderScope(
                                row.assignment,
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="unassigned">Unassigned</option>
                        <option value="anime">Anime</option>
                        <option value="tv">TV</option>
                    </select>
                </div>
            </div>
        </div>

        <template v-for="scope in scopes" :key="scope.key">
            <Separator />
            <div class="space-y-3">
                <h3 class="text-sm font-semibold">{{ scope.label }}</h3>

                <label class="flex items-center gap-2 text-xs">
                    <input
                        type="checkbox"
                        :checked="hasScopeOverride(scope.key)"
                        @change="
                            toggleScopeOverride(
                                scope.key,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                    Override global languages for
                    {{ scope.label.toLowerCase() }}
                </label>
                <Input
                    v-if="hasScopeOverride(scope.key)"
                    :model-value="scopeLanguagesText(scope.key)"
                    type="text"
                    placeholder="Leave empty for no languages"
                    class="h-8 max-w-[320px] text-sm"
                    @update:model-value="
                        setScopeLanguages(scope.key, String($event))
                    "
                />

                <div>
                    <Label class="text-xs text-muted-foreground">
                        Free-text notes (max 4000 characters)
                    </Label>
                    <textarea
                        v-model="state.guidance[scope.key].notes"
                        rows="3"
                        maxlength="4000"
                        placeholder="Reference notes for the Advisor. Cannot create confidence or bypass approval."
                        class="mt-1 w-full rounded-md border border-border bg-transparent px-2 py-1.5 text-sm"
                    />
                </div>

                <SubtitleRuleEditor
                    v-model="state.guidance[scope.key].rules"
                    :scope="scope.key"
                    :strengths="subtitleRuleStrengths"
                    :condition-fields="conditionFields"
                    :errors="errors"
                />
            </div>
        </template>

        <input
            type="hidden"
            name="media_replacement"
            :value="JSON.stringify(state)"
        />
    </div>
</template>
