<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export interface SubtitleRuleCondition {
    field: string;
    value: string;
}

export interface SubtitleRule {
    name: string;
    enabled: boolean;
    strength: string;
    languages: string[];
    conditions: SubtitleRuleCondition[];
    explanation: string;
}

const modelValue = defineModel<SubtitleRule[]>({ required: true });

const props = defineProps<{
    scope: string;
    strengths: Array<{ value: string; label: string }>;
    conditionFields: Array<{ value: string; label: string }>;
    errors: Record<string, string>;
}>();

function defaultStrength(): string {
    return props.strengths[0]?.value ?? 'guarantee';
}

function defaultConditionField(): string {
    return props.conditionFields[0]?.value ?? 'title';
}

function addRule(): void {
    modelValue.value.push({
        name: '',
        enabled: true,
        strength: defaultStrength(),
        languages: [],
        conditions: [{ field: defaultConditionField(), value: '' }],
        explanation: '',
    });
}

function removeRule(index: number): void {
    modelValue.value.splice(index, 1);
}

function addCondition(rule: SubtitleRule): void {
    rule.conditions.push({ field: defaultConditionField(), value: '' });
}

function removeCondition(rule: SubtitleRule, index: number): void {
    rule.conditions.splice(index, 1);
}

function languagesText(rule: SubtitleRule): string {
    return rule.languages.join(', ');
}

function setLanguagesText(rule: SubtitleRule, value: string): void {
    rule.languages = value
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);
}

function ruleError(index: number): string {
    // Exact match or dot-prefixed: a bare startsWith would let `rules.1`
    // also match `rules.10.*` and display rule 10's errors on rule 1.
    const base = `media_replacement.guidance.${props.scope}.rules.${index}`;

    return Object.entries(props.errors)
        .filter(([key]) => key === base || key.startsWith(`${base}.`))
        .map(([, message]) => message)
        .join(' ');
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center justify-between">
            <Label class="text-xs text-muted-foreground">
                Structured rules
            </Label>
            <Button type="button" variant="outline" size="sm" @click="addRule">
                <Plus class="h-3.5 w-3.5" /> Add rule
            </Button>
        </div>

        <p
            v-if="modelValue.length === 0"
            class="text-xs text-muted-foreground italic"
        >
            No rules yet. Add a rule to describe a trusted subtitle convention.
        </p>

        <div
            v-for="(rule, ruleIndex) in modelValue"
            :key="ruleIndex"
            class="space-y-3 rounded-lg border border-border bg-bg-elev p-3"
        >
            <div class="flex items-center gap-2">
                <Input
                    v-model="rule.name"
                    type="text"
                    placeholder="Rule name"
                    class="h-8 flex-1 text-sm"
                />
                <label class="flex items-center gap-1.5 text-xs">
                    <input v-model="rule.enabled" type="checkbox" />
                    Enabled
                </label>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    @click="removeRule(ruleIndex)"
                >
                    <Trash2 class="h-3.5 w-3.5" />
                </Button>
            </div>

            <div class="grid gap-2 sm:grid-cols-2">
                <div>
                    <Label class="text-xs text-muted-foreground"
                        >Strength</Label
                    >
                    <Select v-model="rule.strength">
                        <SelectTrigger class="mt-1 h-8 text-sm">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="strength in strengths"
                                :key="strength.value"
                                :value="strength.value"
                            >
                                {{ strength.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div>
                    <Label class="text-xs text-muted-foreground">
                        Subtitle languages (comma-separated)
                    </Label>
                    <Input
                        :model-value="languagesText(rule)"
                        type="text"
                        placeholder="English, Japanese"
                        class="mt-1 h-8 text-sm"
                        @update:model-value="
                            setLanguagesText(rule, String($event))
                        "
                    />
                </div>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <Label class="text-xs text-muted-foreground">
                        Match conditions (all must match)
                    </Label>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="addCondition(rule)"
                    >
                        <Plus class="h-3.5 w-3.5" /> Add condition
                    </Button>
                </div>
                <div
                    v-for="(condition, conditionIndex) in rule.conditions"
                    :key="conditionIndex"
                    class="flex items-center gap-2"
                >
                    <Select v-model="condition.field">
                        <SelectTrigger class="h-8 w-44 text-sm">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="field in conditionFields"
                                :key="field.value"
                                :value="field.value"
                            >
                                {{ field.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Input
                        v-model="condition.value"
                        type="text"
                        placeholder="Value"
                        class="h-8 flex-1 text-sm"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        @click="removeCondition(rule, conditionIndex)"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                    </Button>
                </div>
            </div>

            <div>
                <Label class="text-xs text-muted-foreground">
                    Explanation (optional)
                </Label>
                <textarea
                    v-model="rule.explanation"
                    rows="2"
                    placeholder="Why this convention is trusted."
                    class="mt-1 w-full rounded-md border border-border bg-transparent px-2 py-1.5 text-sm"
                />
            </div>

            <InputError :message="ruleError(ruleIndex)" />
        </div>
    </div>
</template>
