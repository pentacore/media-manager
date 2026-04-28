<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    { title: 'Profile', href: editProfile() },
    { title: 'Security', href: editSecurity() },
    { title: 'Appearance', href: editAppearance() },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="p-5">
        <div class="grid gap-8 lg:grid-cols-[200px_1fr]">
            <aside>
                <div
                    class="mb-3 text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                >
                    Settings
                </div>
                <nav
                    class="flex flex-col gap-0.5"
                    aria-label="Settings"
                >
                    <Link
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        :href="item.href"
                        :class="
                            cn(
                                'inline-flex h-8 items-center rounded-md px-2.5 text-[13px] font-medium transition-colors',
                                isCurrentOrParentUrl(item.href)
                                    ? 'bg-accent/15 text-accent'
                                    : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                            )
                        "
                    >
                        {{ item.title }}
                    </Link>
                </nav>
            </aside>

            <Separator class="my-2 lg:hidden" />

            <section class="max-w-2xl space-y-8">
                <slot />
            </section>
        </div>
    </div>
</template>
