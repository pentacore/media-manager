<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import ServerController from '@/actions/App/Http/Controllers/Admin/ServerController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const open = ref(false);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button size="sm" class="gap-1.5" data-server-add>
                <Plus class="size-3.5" />Add server
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Add server</DialogTitle>
                <DialogDescription>
                    Name the host. The next screen shows the one-time token and
                    the command that installs the agent.
                </DialogDescription>
            </DialogHeader>
            <Form
                v-bind="ServerController.store.form()"
                class="space-y-4"
                v-slot="{ errors, processing }"
                @success="open = false"
            >
                <div class="grid gap-2">
                    <Label for="server-name">Name</Label>
                    <Input
                        id="server-name"
                        name="name"
                        maxlength="100"
                        autocomplete="off"
                        placeholder="babel"
                        required
                        data-server-name
                    />
                    <InputError :message="errors.name" />
                </div>
                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="processing"
                        data-server-create
                    >
                        Add server
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
