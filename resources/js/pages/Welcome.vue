<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

type PublicServer = {
    id: number;
    name: string;
    description: string | null;
};

defineProps<{
    servers: PublicServer[];
}>();
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
                    Log in and start a server. It runs on its own cloud machine
                    and shuts down after 15 minutes with nobody online, so it
                    only costs money while people play.
                </p>
            </div>

            <section class="space-y-3" aria-labelledby="servers-heading">
                <h2 id="servers-heading" class="text-lg font-medium">
                    Servers
                </h2>
                <ul v-if="servers.length > 0" class="grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="server in servers"
                        :key="server.id"
                        class="rounded-xl border p-4"
                    >
                        <p class="font-medium">{{ server.name }}</p>
                        <p
                            v-if="server.description"
                            class="text-sm text-muted-foreground"
                        >
                            {{ server.description }}
                        </p>
                    </li>
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
    </div>
</template>
