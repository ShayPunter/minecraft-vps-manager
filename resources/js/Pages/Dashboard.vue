<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import Server from "@/Components/Server.vue";

const props = defineProps({
    servers: Array
});

// Method to get the full server icon URL
const getFullServerIconUrl = (server) => {
    // Adjust the base URL as per your environment setup
    const baseUrl = import.meta.env.APP_URL || ''; // For Vite
    // const baseUrl = process.env.MIX_APP_URL || ''; // For Laravel Mix (Webpack)
    return server.server_icon ? `${baseUrl}/${server.server_icon}` : '';
};


</script>

<template>
    <AppLayout title="Dashboard">
        <template #header>
            <h1 class="text-2xl font-semibold text-gray-100">Dashboard</h1>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto">
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
        </div>
    </AppLayout>
</template>
