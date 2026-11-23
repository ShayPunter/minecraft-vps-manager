<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';

// A Copy button for an online server's address (#5450): copies exactly what's shown.
const props = defineProps<{
    address: string;
    serverName: string;
}>();

const state = ref<'idle' | 'copied' | 'failed'>('idle');
let timer: ReturnType<typeof setTimeout> | undefined;

async function copy(): Promise<void> {
    clearTimeout(timer);

    try {
        if (!navigator.clipboard) {
            throw new Error('No clipboard');
        }

        await navigator.clipboard.writeText(props.address);
        state.value = 'copied';
    } catch {
        state.value = 'failed';
    }

    timer = setTimeout(() => (state.value = 'idle'), 2000);
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <Button
        type="button"
        variant="ghost"
        size="sm"
        class="h-7 gap-1 px-2 align-middle"
        :aria-label="`Copy the address of ${serverName}`"
        data-test="copy-address"
        @click="copy"
    >
        <Check v-if="state === 'copied'" class="size-3.5" />
        <Copy v-else class="size-3.5" />
        <span aria-live="polite">{{
            state === 'copied'
                ? 'Copied'
                : state === 'failed'
                  ? "Couldn't copy: select it instead"
                  : 'Copy'
        }}</span>
    </Button>
</template>
