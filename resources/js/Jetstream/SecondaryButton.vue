<script setup>
defineProps({
    type: {
        type: String,
        default: "button",
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center px-4 py-2 bg-gray-500 border border-gray-600 rounded-md font-semibold text-xs text-gray-200 uppercase tracking-widest shadow-sm hover:text-gray-100 focus:outline-none focus:border-blue-300 focus:ring focus:ring-blue-200 active:text-gray-800 active:bg-gray-50 disabled:opacity-25 transition"
    >
        <slot />
    </button>
</template>
