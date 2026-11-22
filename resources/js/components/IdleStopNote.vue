<script setup lang="ts">
import { computed } from 'vue';
import { useCountdown } from '@/composables/useCountdown';
import type { ServerSummary } from '@/types/servers';

/**
 * Who's on, and when the watchdog stops an online server nobody is on (#5378).
 */
const props = defineProps<{
    server: ServerSummary;
}>();

const left = useCountdown(() => props.server.stops_in_seconds);

const minutes = computed(() =>
    left.value === null ? null : Math.ceil(left.value / 60),
);
</script>

<template>
    <template
        v-if="server.players_online !== null && server.players_online > 0"
    >
        {{ server.players_online }} playing, stops {{ server.idle_minutes }} min
        after the last one leaves.
    </template>
    <template v-else>
        <template v-if="server.players_online === null"
            >Checking who's on…
        </template>
        <template v-if="minutes !== null && minutes > 0"
            >Stops in {{ minutes }} min if nobody joins.</template
        >
        <template v-else-if="minutes === 0">Stopping soon.</template>
    </template>
</template>
