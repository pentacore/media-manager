<script setup lang="ts">
import { computed, reactive, toRaw } from 'vue';
import InputError from '@/components/InputError.vue';
import { Field, SettingsGrid, SettingsRow } from '@/components/mm';
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
import type { MediaReplacementScope } from '@/typefinder';
import SubtitleRuleEditor from './SubtitleRuleEditor.vue';
import type { SubtitleRule } from './SubtitleRuleEditor.vue';

interface ScopeGuidance {
    notes: string;
    rules: SubtitleRule[];
}

type Scope = MediaReplacementScope;

export interface SubtitleCheckConfiguration {
    enabled: boolean;
    max_attempts_per_target: number;
    cooldown_hours: number;
}

export interface SubtitleAuditConfiguration {
    search_minutes_per_episode: number;
    min_search_minutes: number;
    max_search_minutes: number;
    in_chat_wait_seconds: number;
    watchdog_delay_seconds: number;
    sweep_grace_minutes: number;
}

export interface MediaReplacementConfiguration {
    automatic_selection_enabled: boolean;
    automatic_selection_threshold: number;
    global_languages: string[];
    scoped_languages: Record<Scope, string[] | null>;
    season_pack_policy: string;
    subtitle_check: SubtitleCheckConfiguration;
    subtitle_audit: SubtitleAuditConfiguration;
    guidance: Record<Scope, ScopeGuidance>;
}

const props = defineProps<{
    configuration: MediaReplacementConfiguration;
    seasonPackPolicies: Array<{ value: string; label: string }>;
    subtitleRuleStrengths: Array<{ value: string; label: string }>;
    conditionFields: Array<{ value: string; label: string }>;
    requestCeilingSeconds: number;
    effectiveWatchdogDelaySeconds: number;
    errors: Record<string, string>;
}>();

const defaultSubtitleCheck: SubtitleCheckConfiguration = {
    enabled: false,
    max_attempts_per_target: 1,
    cooldown_hours: 24,
};

const defaultSubtitleAudit: SubtitleAuditConfiguration = {
    search_minutes_per_episode: 3,
    min_search_minutes: 30,
    max_search_minutes: 720,
    in_chat_wait_seconds: 60,
    watchdog_delay_seconds: 180,
    sweep_grace_minutes: 5,
};

const incomingConfiguration = structuredClone(toRaw(props.configuration));

// The block is always present in a normalized configuration; the fallback keeps
// the bindings defined if the page is ever handed an older payload that predates
// it, since the hidden field posts `state` verbatim.
const state = reactive<MediaReplacementConfiguration>({
    ...incomingConfiguration,
    subtitle_check: {
        ...defaultSubtitleCheck,
        ...incomingConfiguration.subtitle_check,
    },
    subtitle_audit: {
        ...defaultSubtitleAudit,
        ...incomingConfiguration.subtitle_audit,
    },
});

interface SubtitleAuditField {
    key: keyof SubtitleAuditConfiguration;
    label: string;
    hint: string;
    min: number;
    max: number;
}

const subtitleAuditFields = computed<SubtitleAuditField[]>(() => [
    {
        key: 'search_minutes_per_episode',
        label: 'Search minutes per episode',
        hint: 'Search time allowed per missing episode when the chat repairs the subtitles of a series. The searches run one after another.',
        min: 1,
        max: 30,
    },
    {
        key: 'min_search_minutes',
        label: 'Shortest search window (minutes)',
        hint: 'A repair batch gets at least this long, however few episodes are missing. Another repair of the same series waits until the window ends. Must not exceed the longest window.',
        min: 5,
        max: 720,
    },
    {
        key: 'max_search_minutes',
        label: 'Longest search window (minutes)',
        hint: 'A repair batch gets at most this long, however many episodes are missing.',
        min: 30,
        max: 1440,
    },
    {
        key: 'in_chat_wait_seconds',
        label: 'Chat wait (seconds)',
        hint: 'How long the chat waits for the searches before it hands the batch off and admins are notified instead. 0 hands it off at once. Never longer than what is left of the request.',
        min: 0,
        max: 120,
    },
    {
        key: 'watchdog_delay_seconds',
        label: 'Watchdog delay (seconds)',
        hint:
            props.requestCeilingSeconds > 0
                ? `When the chat never shows or hands off a batch, it is picked up after this delay so admins still get notified. Must be longer than the server's request time limit (${props.requestCeilingSeconds} s).`
                : 'When the chat never shows or hands off a batch, it is picked up after this delay so admins still get notified.',
        min: props.requestCeilingSeconds + 1,
        max: 3600,
    },
    {
        key: 'sweep_grace_minutes',
        label: 'Expiry grace (minutes)',
        hint: 'How long past its search window a batch waits before unfinished searches are marked failed and the batch is settled. At least 5 minutes, so a running search is never cut off.',
        min: 5,
        max: 120,
    },
]);

const watchdogDelayClamped = computed(
    () =>
        props.effectiveWatchdogDelaySeconds !==
        props.configuration.subtitle_audit?.watchdog_delay_seconds,
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

        <SettingsGrid>
            <SettingsRow>
                <template #label>
                    <Field
                        for="media-replacement-automatic-selection"
                        label="Automatic selection"
                        hint="When enabled, a unique candidate above the confidence threshold may be queued without asking you to choose. The Action Rule still governs execution."
                    >
                        <span />
                    </Field>
                </template>
                <label class="flex items-center gap-2 text-sm touch:min-h-10">
                    <input
                        id="media-replacement-automatic-selection"
                        aria-labelledby="media-replacement-automatic-selection-label"
                        aria-describedby="media-replacement-automatic-selection-description media-replacement-automatic-selection-hint"
                        v-model="state.automatic_selection_enabled"
                        type="checkbox"
                    />
                    <span id="media-replacement-automatic-selection-description"
                        >Enable automatic candidate selection</span
                    >
                </label>
            </SettingsRow>

            <SettingsRow>
                <template #label>
                    <Field
                        for="media-replacement-confidence-threshold"
                        label="Confidence threshold"
                        hint="Minimum subtitle confidence (0–100) required before a candidate can be selected automatically."
                    >
                        <span />
                    </Field>
                </template>
                <Input
                    id="media-replacement-confidence-threshold"
                    aria-describedby="media-replacement-confidence-threshold-hint"
                    v-model.number="state.automatic_selection_threshold"
                    type="number"
                    min="0"
                    max="100"
                    class="h-8 max-w-[120px] text-sm"
                />
            </SettingsRow>

            <SettingsRow>
                <template #label>
                    <Field
                        for="media-replacement-subtitle-check"
                        label="Automatic subtitle check"
                        hint="Check completed downloads for the required subtitle languages when the series or movie carries a subtitle-check tag. Tags are configured per connection on the connection's own settings page."
                    >
                        <span />
                    </Field>
                </template>
                <label class="flex items-center gap-2 text-sm touch:min-h-10">
                    <input
                        id="media-replacement-subtitle-check"
                        aria-labelledby="media-replacement-subtitle-check-label"
                        aria-describedby="media-replacement-subtitle-check-description media-replacement-subtitle-check-hint"
                        v-model="state.subtitle_check.enabled"
                        type="checkbox"
                    />
                    <span id="media-replacement-subtitle-check-description"
                        >Check tagged imports for missing subtitles</span
                    >
                </label>
            </SettingsRow>

            <SettingsRow>
                <template #label>
                    <Field
                        for="media-replacement-attempts-per-item"
                        label="Attempts per item"
                        hint="How many replacements the automatic check may request for the same item inside the cooldown window."
                    >
                        <span />
                    </Field>
                </template>
                <Input
                    id="media-replacement-attempts-per-item"
                    aria-describedby="media-replacement-attempts-per-item-hint"
                    v-model.number="
                        state.subtitle_check.max_attempts_per_target
                    "
                    type="number"
                    min="1"
                    max="10"
                    class="h-8 max-w-[120px] text-sm"
                />
            </SettingsRow>

            <SettingsRow>
                <template #label>
                    <Field
                        for="media-replacement-cooldown-hours"
                        label="Cooldown (hours)"
                        hint="Window over which the attempts-per-item limit is counted."
                    >
                        <span />
                    </Field>
                </template>
                <Input
                    id="media-replacement-cooldown-hours"
                    aria-describedby="media-replacement-cooldown-hours-hint"
                    v-model.number="state.subtitle_check.cooldown_hours"
                    type="number"
                    min="1"
                    max="720"
                    class="h-8 max-w-[120px] text-sm"
                />
            </SettingsRow>
        </SettingsGrid>

        <Separator />
        <div>
            <h3 class="text-sm font-semibold">Series subtitle repair</h3>
            <p class="mt-1 text-[13px] text-muted-foreground">
                Limits for the batch the chat starts when it repairs the missing
                subtitles of a whole series. How many searches run at once is
                set per Sonarr connection.
            </p>
        </div>

        <SettingsGrid>
            <SettingsRow v-for="field in subtitleAuditFields" :key="field.key">
                <template #label>
                    <Field
                        :for="`media-replacement-subtitle-audit-${field.key}`"
                        :label="field.label"
                        :hint="field.hint"
                    >
                        <span />
                    </Field>
                </template>
                <div class="space-y-1">
                    <Input
                        :id="`media-replacement-subtitle-audit-${field.key}`"
                        :aria-describedby="`media-replacement-subtitle-audit-${field.key}-hint`"
                        v-model.number="state.subtitle_audit[field.key]"
                        type="number"
                        :min="field.min"
                        :max="field.max"
                        class="h-8 max-w-[120px] text-sm"
                    />
                    <p
                        v-if="
                            field.key === 'watchdog_delay_seconds' &&
                            watchdogDelayClamped
                        "
                        class="text-xs text-warning"
                        data-subtitle-audit-watchdog-effective
                    >
                        The saved delay is not past the request time limit, so
                        the watchdog runs after
                        {{ effectiveWatchdogDelaySeconds }} s.
                    </p>
                    <InputError
                        :message="
                            errors[
                                `media_replacement.subtitle_audit.${field.key}`
                            ]
                        "
                    />
                </div>
            </SettingsRow>
        </SettingsGrid>

        <Separator />

        <SettingsGrid>
            <SettingsRow>
                <template #label>
                    <Field
                        for="media-replacement-languages"
                        label="Preferred subtitle languages"
                        hint="Global comma-separated list of required subtitle languages. Scopes inherit this unless overridden."
                    >
                        <span />
                    </Field>
                </template>
                <Input
                    id="media-replacement-languages"
                    aria-describedby="media-replacement-languages-hint"
                    :model-value="languagesToText(state.global_languages)"
                    type="text"
                    placeholder="English, Swedish"
                    class="h-8 max-w-[320px] text-sm"
                    @update:model-value="setGlobalLanguages(String($event))"
                />
            </SettingsRow>

            <SettingsRow>
                <template #label>
                    <Field
                        for="media-replacement-season-pack-policy"
                        label="Season-pack policy"
                        hint="Controls whether a season pack may replace an individual episode, and whether it forces approval."
                    >
                        <span />
                    </Field>
                </template>
                <Select v-model="state.season_pack_policy">
                    <SelectTrigger
                        id="media-replacement-season-pack-policy"
                        aria-describedby="media-replacement-season-pack-policy-hint"
                        aria-labelledby="media-replacement-season-pack-policy-label media-replacement-season-pack-policy"
                        class="h-8 max-w-[320px] text-sm"
                    >
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
            </SettingsRow>
        </SettingsGrid>

        <template v-for="scope in scopes" :key="scope.key">
            <Separator />
            <div class="space-y-3">
                <h3 class="text-sm font-semibold">{{ scope.label }}</h3>

                <label class="flex items-center gap-2 text-xs touch:min-h-10">
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
                    :id="`media-replacement-${scope.key}-languages`"
                    :aria-label="`${scope.label} subtitle languages`"
                    :model-value="scopeLanguagesText(scope.key)"
                    type="text"
                    placeholder="Leave empty for no languages"
                    class="h-8 max-w-[320px] text-sm"
                    @update:model-value="
                        setScopeLanguages(scope.key, String($event))
                    "
                />

                <div>
                    <Label
                        :for="`media-replacement-${scope.key}-notes`"
                        class="text-xs text-muted-foreground"
                    >
                        Free-text notes (max 4000 characters)
                    </Label>
                    <textarea
                        :id="`media-replacement-${scope.key}-notes`"
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
