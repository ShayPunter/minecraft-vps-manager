<script setup>
import Server from "@/Components/Server.vue";

const props = defineProps({
    servers: Array
});

</script>

<template>
    <div class="container mx-auto my-8">
        <ul
            role="list"
            class="grid grid-cols-1 gap-6 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4"
        >
            <Server
                v-for="server in servers"
                :key="server.server_id"
                :id="server.server_id"
                :name="server.name"
                :img="server.server_icon"
                :ip="server.ip_address"
                :status="server.status"
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
