<script setup lang="ts">
import { reactive, toRaw } from 'vue';
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

export interface SubtitleCheckConfiguration {
    enabled: boolean;
    max_attempts_per_target: number;
    cooldown_hours: number;
}

export interface MediaReplacementConfiguration {
    automatic_selection_enabled: boolean;
    automatic_selection_threshold: number;
    global_languages: string[];
    scoped_languages: Record<Scope, string[] | null>;
    season_pack_policy: string;
    subtitle_check: SubtitleCheckConfiguration;
    guidance: Record<Scope, ScopeGuidance>;
}

const props = defineProps<{
    configuration: MediaReplacementConfiguration;
    seasonPackPolicies: Array<{ value: string; label: string }>;
    subtitleRuleStrengths: Array<{ value: string; label: string }>;
    conditionFields: Array<{ value: string; label: string }>;
    errors: Record<string, string>;
}>();

const defaultSubtitleCheck: SubtitleCheckConfiguration = {
    enabled: false,
    max_attempts_per_target: 1,
    cooldown_hours: 24,
};

const incomingConfiguration = structuredClone(toRaw(props.configuration));

// The block is always present in a normalized configuration; the fallback keeps
// the bindings defined if the page is ever handed an older payload that predates
// it, since the hidden field posts `state` verbatim.
const state = reactive<MediaReplacementConfiguration>({
    ...incomingConfiguration,
    subtitle_check: {
        ...defaultSubtitleCheck,
        ...(incomingConfiguration.subtitle_check ?? {}),
    },
});

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
                label="Automatic subtitle check"
                hint="Check completed downloads for the required subtitle languages when the series or movie carries a subtitle-check tag. Tags are configured per connection on the connection's own settings page."
            >
                <span />
            </Field>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="state.subtitle_check.enabled" type="checkbox" />
                Check tagged imports for missing subtitles
            </label>
        </div>

        <div
            class="grid items-start gap-6"
            style="grid-template-columns: 200px 1fr"
        >
            <Field
                label="Attempts per item"
                hint="How many replacements the automatic check may request for the same item inside the cooldown window."
            >
                <span />
            </Field>
            <Input
                v-model.number="state.subtitle_check.max_attempts_per_target"
                type="number"
                min="1"
                max="10"
                class="h-8 max-w-[120px] text-sm"
            />
        </div>

        <div
            class="grid items-start gap-6"
            style="grid-template-columns: 200px 1fr"
        >
            <Field
                label="Cooldown (hours)"
                hint="Window over which the attempts-per-item limit is counted."
            >
                <span />
            </Field>
            <Input
                v-model.number="state.subtitle_check.cooldown_hours"
                type="number"
                min="1"
                max="720"
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
