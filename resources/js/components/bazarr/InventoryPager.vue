<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    page: number;
    perPage: number;
    total: number;
}>();

const emit = defineEmits<{
    change: [page: number];
}>();

const lastPage = computed(() =>
    Math.max(1, Math.ceil(props.total / Math.max(1, props.perPage))),
);
const firstOnPage = computed(() =>
    props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1,
);
const lastOnPage = computed(() =>
    Math.min(props.total, props.page * props.perPage),
);
</script>

<template>
    <nav
        aria-label="Inventory pages"
        class="flex flex-wrap items-center justify-between gap-3"
        data-test="inventory-pager"
    >
        <p
            class="text-sm text-muted-foreground"
            data-test="inventory-pager-summary"
        >
            Showing {{ firstOnPage }}–{{ lastOnPage }} of {{ total }}
        </p>
        <div class="flex items-center gap-2">
            <span class="text-sm text-muted-foreground">
                Page {{ page }} of {{ lastPage }}
            </span>
            <Button
                size="sm"
                variant="outline"
                data-test="inventory-pager-previous"
                :disabled="page <= 1"
                @click="emit('change', page - 1)"
            >
                Previous
            </Button>
            <Button
                size="sm"
                variant="outline"
                data-test="inventory-pager-next"
                :disabled="page >= lastPage"
                @click="emit('change', page + 1)"
            >
                Next
            </Button>
        </div>
    </nav>
</template>
