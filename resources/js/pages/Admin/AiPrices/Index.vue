<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

interface PriceRow {
    id: number;
    provider: string;
    model: string;
    input_per_mtok: string;
    output_per_mtok: string;
    cache_read_per_mtok: string;
    cache_write_per_mtok: string;
    reasoning_per_mtok: string;
}

defineProps<{
    prices: PriceRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: '#' },
            { title: 'AI Prices', href: AiModelPriceController.index.url() },
        ],
    },
});

const showCreateDialog = ref(false);
const editing = ref<PriceRow | null>(null);

function startEdit(price: PriceRow) {
    editing.value = { ...price };
}

function cancelEdit() {
    editing.value = null;
}

function destroy(price: PriceRow) {
    if (!confirm(`Remove pricing for ${price.provider}/${price.model}?`)) {
        return;
    }

    router.visit(AiModelPriceController.destroy.url(price.id), {
        method: 'delete',
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="AI Model Prices" />

    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">AI Model Prices</h1>
                <p class="text-sm text-muted-foreground">
                    Per-million-token rates used to estimate cost on the AI
                    Usage dashboard. Add a row for any model you've used so its
                    spend shows up.
                </p>
            </div>

            <Dialog v-model:open="showCreateDialog">
                <DialogTrigger as-child>
                    <Button>Add Model Price</Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Model Price</DialogTitle>
                    </DialogHeader>
                    <Form
                        v-bind="AiModelPriceController.store.post()"
                        class="space-y-4"
                        v-slot="{ errors, processing }"
                        @success="showCreateDialog = false"
                    >
                        <div class="space-y-2">
                            <Label for="provider">Provider</Label>
                            <Input
                                id="provider"
                                name="provider"
                                placeholder="openai, anthropic, gemini, …"
                            />
                            <InputError :message="errors.provider" />
                        </div>
                        <div class="space-y-2">
                            <Label for="model">Model</Label>
                            <Input
                                id="model"
                                name="model"
                                placeholder="gpt-5-mini"
                            />
                            <InputError :message="errors.model" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <Label for="input_per_mtok">Input ($/M)</Label>
                                <Input
                                    id="input_per_mtok"
                                    name="input_per_mtok"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                />
                                <InputError :message="errors.input_per_mtok" />
                            </div>
                            <div class="space-y-2">
                                <Label for="output_per_mtok"
                                    >Output ($/M)</Label
                                >
                                <Input
                                    id="output_per_mtok"
                                    name="output_per_mtok"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                />
                                <InputError :message="errors.output_per_mtok" />
                            </div>
                            <div class="space-y-2">
                                <Label for="cache_read_per_mtok"
                                    >Cache Read ($/M)</Label
                                >
                                <Input
                                    id="cache_read_per_mtok"
                                    name="cache_read_per_mtok"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                />
                                <InputError
                                    :message="errors.cache_read_per_mtok"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="cache_write_per_mtok"
                                    >Cache Write ($/M)</Label
                                >
                                <Input
                                    id="cache_write_per_mtok"
                                    name="cache_write_per_mtok"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                />
                                <InputError
                                    :message="errors.cache_write_per_mtok"
                                />
                            </div>
                            <div class="col-span-2 space-y-2">
                                <Label for="reasoning_per_mtok"
                                    >Reasoning ($/M)</Label
                                >
                                <Input
                                    id="reasoning_per_mtok"
                                    name="reasoning_per_mtok"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                />
                                <InputError
                                    :message="errors.reasoning_per_mtok"
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="submit" :disabled="processing"
                                >Save</Button
                            >
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Configured Models</CardTitle>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Provider</TableHead>
                            <TableHead>Model</TableHead>
                            <TableHead class="text-right">Input</TableHead>
                            <TableHead class="text-right">Output</TableHead>
                            <TableHead class="text-right">Cache R</TableHead>
                            <TableHead class="text-right">Cache W</TableHead>
                            <TableHead class="text-right">Reasoning</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="price in prices" :key="price.id">
                            <TableCell class="font-medium">{{
                                price.provider
                            }}</TableCell>
                            <TableCell class="font-mono text-xs">{{
                                price.model
                            }}</TableCell>
                            <TableCell class="text-right"
                                >${{ price.input_per_mtok }}</TableCell
                            >
                            <TableCell class="text-right"
                                >${{ price.output_per_mtok }}</TableCell
                            >
                            <TableCell class="text-right"
                                >${{ price.cache_read_per_mtok }}</TableCell
                            >
                            <TableCell class="text-right"
                                >${{ price.cache_write_per_mtok }}</TableCell
                            >
                            <TableCell class="text-right"
                                >${{ price.reasoning_per_mtok }}</TableCell
                            >
                            <TableCell class="text-right">
                                <div class="flex justify-end gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="startEdit(price)"
                                        >Edit</Button
                                    >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        @click="destroy(price)"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableEmpty v-if="prices.length === 0" :colspan="8">
                            No models priced yet. Click "Add Model Price" to
                            start.
                        </TableEmpty>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <Dialog
            :open="editing !== null"
            @update:open="(v) => !v && cancelEdit()"
        >
            <DialogContent v-if="editing">
                <DialogHeader>
                    <DialogTitle
                        >Edit {{ editing.provider }} /
                        {{ editing.model }}</DialogTitle
                    >
                </DialogHeader>
                <Form
                    v-bind="AiModelPriceController.update.form(editing.id)"
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                    @success="cancelEdit"
                >
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <Label for="edit_input">Input ($/M)</Label>
                            <Input
                                id="edit_input"
                                name="input_per_mtok"
                                type="number"
                                step="0.0001"
                                min="0"
                                :default-value="editing.input_per_mtok"
                            />
                            <InputError :message="errors.input_per_mtok" />
                        </div>
                        <div class="space-y-2">
                            <Label for="edit_output">Output ($/M)</Label>
                            <Input
                                id="edit_output"
                                name="output_per_mtok"
                                type="number"
                                step="0.0001"
                                min="0"
                                :default-value="editing.output_per_mtok"
                            />
                            <InputError :message="errors.output_per_mtok" />
                        </div>
                        <div class="space-y-2">
                            <Label for="edit_cache_r">Cache Read ($/M)</Label>
                            <Input
                                id="edit_cache_r"
                                name="cache_read_per_mtok"
                                type="number"
                                step="0.0001"
                                min="0"
                                :default-value="editing.cache_read_per_mtok"
                            />
                            <InputError :message="errors.cache_read_per_mtok" />
                        </div>
                        <div class="space-y-2">
                            <Label for="edit_cache_w">Cache Write ($/M)</Label>
                            <Input
                                id="edit_cache_w"
                                name="cache_write_per_mtok"
                                type="number"
                                step="0.0001"
                                min="0"
                                :default-value="editing.cache_write_per_mtok"
                            />
                            <InputError
                                :message="errors.cache_write_per_mtok"
                            />
                        </div>
                        <div class="col-span-2 space-y-2">
                            <Label for="edit_reasoning">Reasoning ($/M)</Label>
                            <Input
                                id="edit_reasoning"
                                name="reasoning_per_mtok"
                                type="number"
                                step="0.0001"
                                min="0"
                                :default-value="editing.reasoning_per_mtok"
                            />
                            <InputError :message="errors.reasoning_per_mtok" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="processing"
                            >Save</Button
                        >
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
