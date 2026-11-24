<script setup lang="ts">
// The MediaManager mark: an italic serif "m" on an accent tile, matching
// public/favicon.svg. It scales with the size class the caller passes.
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 28 28"
        role="img"
        aria-label="MediaManager"
        data-app-logo-icon
    >
        <rect width="28" height="28" rx="7" class="fill-accent" />
        <text
            x="14"
            y="14"
            text-anchor="middle"
            dominant-baseline="central"
            font-size="16"
            class="fill-accent-foreground font-serif font-semibold italic"
        >
            m
        </text>
    </svg>
</template>
