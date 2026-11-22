<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 42"
        :class="className"
        v-bind="$attrs"
    >
        <!-- A plain block (cube): the app's own mark, not the starter kit's Laravel logo. -->
        <path fill="currentColor" d="M20 2 38 12 20 22 2 12Z" />
        <path
            fill="currentColor"
            fill-opacity="0.7"
            d="M2 14.5 19 24v16L2 30.5Z"
        />
        <path
            fill="currentColor"
            fill-opacity="0.45"
            d="M38 14.5 21 24v16l17-9.5Z"
        />
    </svg>
</template>
