<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    History,
    LayoutDashboard,
    Library,
    ListX,
    Settings,
} from '@lucide/vue';
import { computed } from 'vue';
import AdminController from '@/actions/App/Http/Controllers/Bazarr/AdminController';
import HistoryController from '@/actions/App/Http/Controllers/Bazarr/HistoryController';
import LibraryController from '@/actions/App/Http/Controllers/Bazarr/LibraryController';
import MissingController from '@/actions/App/Http/Controllers/Bazarr/MissingController';
import OverviewController from '@/actions/App/Http/Controllers/Bazarr/OverviewController';

type TabName = 'overview' | 'missing' | 'library' | 'history' | 'admin';

const props = defineProps<{
    active: TabName;
    connections: { id: number; name: string }[];
    selectedConnectionId: number | null;
}>();

const page = usePage();
const isAdmin = computed(() => {
    const role = page.props.auth.user?.role;
    const value = typeof role === 'string' ? role : role?.value;

    return value === 'admin';
});

const tabs = computed(() => [
    {
        name: 'overview' as const,
        label: 'Overview',
        icon: LayoutDashboard,
        route: OverviewController,
    },
    {
        name: 'missing' as const,
        label: 'Missing',
        icon: ListX,
        route: MissingController,
    },
    {
        name: 'library' as const,
        label: 'Library',
        icon: Library,
        route: LibraryController,
    },
    {
        name: 'history' as const,
        label: 'History',
        icon: History,
        route: HistoryController,
    },
    ...(isAdmin.value
        ? [
              {
                  name: 'admin' as const,
                  label: 'Admin',
                  icon: Settings,
                  route: AdminController.index,
              },
          ]
        : []),
]);

function tabUrl(tab: (typeof tabs.value)[number]): string {
    return tab.route.url({
        query: props.selectedConnectionId
            ? { connection: props.selectedConnectionId }
            : {},
    });
}

function selectConnection(event: Event): void {
    const connection = Number((event.target as HTMLSelectElement).value);
    const activeTab =
        tabs.value.find((tab) => tab.name === props.active) ?? tabs.value[0];

    router.visit(activeTab.route.url({ query: { connection } }));
}
</script>

<template>
    <div class="space-y-4">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <h1 class="text-2xl font-bold tracking-tight">
                    Subtitle Center
                </h1>
                <p class="text-sm text-muted-foreground">
                    Inspect subtitle coverage and Bazarr activity.
                </p>
            </div>

            <label
                v-if="connections.length > 1"
                class="flex flex-col gap-1 text-sm font-medium"
            >
                Bazarr connection
                <select
                    data-test="bazarr-connection"
                    class="h-9 min-w-48 rounded-md border border-input bg-background px-3 text-sm"
                    :value="selectedConnectionId ?? ''"
                    @change="selectConnection"
                >
                    <option disabled value="">Select a connection</option>
                    <option
                        v-for="connection in connections"
                        :key="connection.id"
                        :value="connection.id"
                    >
                        {{ connection.name }}
                    </option>
                </select>
            </label>
        </div>

        <nav
            aria-label="Subtitle Center"
            class="flex gap-1 overflow-x-auto border-b border-border"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.name"
                :href="tabUrl(tab)"
                class="flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition-colors"
                :class="
                    active === tab.name
                        ? 'border-primary text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                :data-test="`subtitle-tab-${tab.name}`"
            >
                <component :is="tab.icon" class="size-4" />
                {{ tab.label }}
            </Link>
        </nav>
    </div>
</template>
