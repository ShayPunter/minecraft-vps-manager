<script setup lang="ts">
import { Head, usePage, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import ServerCard from '@/components/ServerCard.vue';
import { dashboard } from '@/routes';
import type { ServerSummary } from '@/types/servers';

defineProps<{
    servers: ServerSummary[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

// Statuses move on server side; reload just the list every few seconds.
usePoll(5000, { only: ['servers'] });
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            :title="`Hi ${user.name}`"
            description="Start a Minecraft server here. It shuts itself down after 15 minutes with nobody online."
        />

        <ul v-if="servers.length > 0" class="grid gap-3 md:grid-cols-2">
            <ServerCard
                v-for="server in servers"
                :key="server.id"
                :server="server"
            />
        </ul>
        <div
            v-else
            class="rounded-xl border border-dashed border-sidebar-border/70 p-8 text-center text-sm text-muted-foreground"
            data-test="no-servers"
        >
            No servers yet. An admin adds them.
        </div>
    </div>
</template>
