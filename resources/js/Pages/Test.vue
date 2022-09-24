<script setup>
import Server from "@/Components/ServerTemplate.vue";
</script>

<template>
    <div class="w-full bg-red-400">
        <p class="text-white text-center">Testing Page</p>
    </div>

    <div class="container mx-auto my-8">
        <ul
            role="list"
            class="grid grid-cols-1 gap-6 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4"
        >
            <Server
                id="atm8"
                name="All The Mods 8"
                img="atm8.jpg"
                ip="0.0.0.0"
                status="offline"
            ></Server>
        </ul>
    </div>
</template>

<style>
html,
body {
    background-color: #111827;
}
</style>

<script>
export default {
    name: "IndexPage",
};
</script>
