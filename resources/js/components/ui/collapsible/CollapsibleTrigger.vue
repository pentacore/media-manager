<script setup lang="ts">
import type { CollapsibleTriggerProps } from "reka-ui"
import { CollapsibleTrigger, injectCollapsibleRootContext, useId } from "reka-ui"

const props = defineProps<CollapsibleTriggerProps>()

/**
 * reka's CollapsibleContent fills the root's non-reactive `contentId` in its
 * own setup, after the trigger has rendered, so a closed trigger kept an
 * empty `aria-controls`. Seeding it here, before reka's trigger renders,
 * gives both the same id; the content's `||=` keeps it.
 */
const rootContext = injectCollapsibleRootContext()
rootContext.contentId ||= useId(undefined, "reka-collapsible-content")
</script>

<template>
  <CollapsibleTrigger
    data-slot="collapsible-trigger"
    v-bind="props"
  >
    <slot />
  </CollapsibleTrigger>
</template>
