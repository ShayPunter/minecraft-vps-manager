<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { start } from '@/routes/servers';
import type { ServerSummary } from '@/types/servers';

defineProps<{
    server: ServerSummary;
}>();

const busy = ['provisioning', 'booting', 'starting', 'stopping'];
</script>

<template>
    <li
        class="flex flex-col gap-3 rounded-xl border p-4"
        :data-test="`server-${server.slug}`"
    >
        <div class="flex items-start gap-3">
            <img
                v-if="server.icon_url"
                :src="server.icon_url"
                alt=""
                class="size-10 rounded-md object-cover"
            />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ server.name }}</p>
                <p
                    v-if="server.description"
                    class="text-sm text-muted-foreground"
                >
                    {{ server.description }}
                </p>
            </div>
            <Badge
                :variant="server.status === 'online' ? 'default' : 'secondary'"
                data-test="server-status"
                >{{ server.status_label }}</Badge
            >
        </div>

        <p v-if="server.address" class="text-sm" data-test="server-address">
            Connect to
            <code class="rounded bg-muted px-1 py-0.5 font-mono">{{
                server.address
            }}</code>
        </p>
        <p
            v-else-if="busy.includes(server.status)"
            class="text-sm text-muted-foreground"
        >
            This takes a few minutes. The page updates by itself.
        </p>

        <p
            v-if="server.error"
            class="text-sm text-destructive"
            data-test="server-error"
        >
            Last start failed: {{ server.error }}
        </p>

        <Form
            v-if="server.can_start"
            v-bind="start.form(server.slug)"
            :options="{ preserveScroll: true }"
            v-slot="{ processing }"
        >
            <Button
                type="submit"
                size="sm"
                :disabled="processing"
                :aria-label="`Start ${server.name}`"
                >Start</Button
            >
        </Form>
    </li>
</template>
