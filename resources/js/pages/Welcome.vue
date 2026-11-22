<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ServerCard from '@/components/ServerCard.vue';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import { dashboard, login } from '@/routes';
import type { ServerSummary } from '@/types/servers';

defineProps<{
    servers: ServerSummary[];
}>();

usePoll(5000, { only: ['servers'] });
</script>

<template>
    <Head title="Welcome" />

    <div
        class="flex min-h-screen flex-col items-center bg-background p-6 text-foreground lg:p-8"
    >
        <header class="flex w-full max-w-3xl items-center justify-between">
            <div class="flex items-center gap-2 font-medium">
                <AppLogoIcon class="size-6 fill-current" />
                <span>{{ $page.props.name }}</span>
            </div>
            <nav>
                <Button v-if="$page.props.auth.user" variant="outline" as-child>
                    <Link :href="dashboard()">Dashboard</Link>
                </Button>
                <Button v-else variant="outline" as-child>
                    <Link :href="login()">Log in</Link>
                </Button>
            </nav>
        </header>

        <main class="mt-16 flex w-full max-w-3xl flex-col gap-8">
            <div class="space-y-2">
                <h1 class="text-3xl font-semibold tracking-tight">
                    Minecraft servers, on when you play
                </h1>
                <p class="text-muted-foreground">
                    Start a server below (or log in for the private ones). It
                    runs on its own cloud machine and shuts down after 15
                    minutes with nobody online, so it only costs money while
                    people play.
                </p>
            </div>

            <section class="space-y-3" aria-labelledby="servers-heading">
                <h2 id="servers-heading" class="text-lg font-medium">
                    Servers
                </h2>
                <ul v-if="servers.length > 0" class="grid gap-3 sm:grid-cols-2">
                    <ServerCard
                        v-for="server in servers"
                        :key="server.id"
                        :server="server"
                    />
                </ul>
                <p
                    v-else
                    class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
                    data-test="no-public-servers"
                >
                    No public servers yet.
                </p>
            </section>
        </main>

        <!-- The welcome page has no app layout, so it mounts its own (Start answers with a toast). -->
        <Toaster />
    </div>
</template>
