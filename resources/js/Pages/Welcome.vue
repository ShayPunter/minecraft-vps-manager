<script setup>
import Server from "@/Components/Server.vue";
</script>

<template>
    <div class="container mx-auto my-8">
        <ul
            role="list"
            class="grid grid-cols-1 gap-6 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4"
        >
            <Server
                id="ftbone"
                name="FTB One"
                img="ftbone.png"
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

    data() {
        return {
            startingserver: false,
            ftbone: {
                ip: "0.0.0.0",
                status: "online",
                img: window.location.origin + "/img/ftbone.png",
            },
            allthemods6: {
                ip: "0.0.0.0",
                status: "offline",
            },
        };
    },

    created() {
        axios.get(route("check-server-status")).then((res) => {});
    },
};
</script>
