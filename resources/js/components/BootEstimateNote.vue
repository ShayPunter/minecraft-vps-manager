<script setup lang="ts">
import { computed } from 'vue';
import { useCountdown } from '@/composables/useCountdown';
import type { ServerSummary } from '@/types/servers';

/**
 * How long a start usually takes, and while starting how long is left (#5379).
 */
const props = defineProps<{
    server: ServerSummary;
}>();

const left = useCountdown(() =>
    props.server.typical_boot_seconds === null ||
    props.server.boot_elapsed_seconds === null
        ? null
        : Math.max(
              0,
              props.server.typical_boot_seconds -
                  props.server.boot_elapsed_seconds,
          ),
);

const typicalMinutes = computed(() =>
    props.server.typical_boot_seconds === null
        ? null
        : Math.max(1, Math.round(props.server.typical_boot_seconds / 60)),
);

const minutesLeft = computed(() =>
    left.value === null ? null : Math.ceil(left.value / 60),
);
</script>

<template>
    <template v-if="server.boot_elapsed_seconds !== null">
        <template v-if="minutesLeft !== null && minutesLeft > 0"
            >About {{ minutesLeft }} min left.</template
        >
        <template v-else-if="minutesLeft === 0">Any moment now.</template>
    </template>
    <template v-else-if="typicalMinutes !== null"
        >Usually ready in about {{ typicalMinutes }} min.</template
    >
</template>
