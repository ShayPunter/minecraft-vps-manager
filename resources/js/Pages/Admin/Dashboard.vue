<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import Server from "@/Components/Server.vue";
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
                        id="atm8"
                        name="All The Mods 8"
                        img="atm8.jpg"
                        ip="0.0.0.0"
                        status="offline"
                    ></Server>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
