<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ServerCard from '@/components/ServerCard.vue';
import { Toaster } from '@/components/ui/sonner';
import { useStatusPoll } from '@/composables/useStatusPoll';
import type { ServerSummary } from '@/types/servers';

// A link-only server's share page (#5462): this one server, for anyone with the link.
const props = defineProps<{
    server: ServerSummary;
    token: string;
}>();

useStatusPoll(() => [props.server.status], ['server']);
</script>

<template>
    <Head :title="server.name">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <div
        class="flex min-h-screen flex-col items-center bg-background p-6 text-foreground lg:p-8"
    >
        <header class="flex w-full max-w-xl items-center gap-2 font-medium">
            <AppLogoIcon class="size-6 fill-current" />
            <span>{{ $page.props.name }}</span>
        </header>

        <main class="mt-16 flex w-full max-w-xl flex-col gap-6">
            <div class="space-y-2">
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ server.name }}
                </h1>
                <p class="text-muted-foreground">
                    Someone shared this server with you. Start it below: it runs
                    on its own cloud machine and shuts down after
                    {{ server.idle_minutes }} minutes with nobody online. Keep
                    the link to yourselves: anyone who has it can start it.
                </p>
            </div>

            <ul>
                <ServerCard :server="server" :share-token="token" />
            </ul>
        </main>

        <!-- No app layout here either, so it mounts its own (Start answers with a toast). -->
        <Toaster />
    </div>
</template>
