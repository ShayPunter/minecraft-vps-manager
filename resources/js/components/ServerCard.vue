<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import BootEstimateNote from '@/components/BootEstimateNote.vue';
import CopyAddress from '@/components/CopyAddress.vue';
import IdleStopNote from '@/components/IdleStopNote.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SharedServerController from '@/actions/App/Http/Controllers/SharedServerController';
import { start, stop } from '@/routes/servers';
import type { ServerSummary } from '@/types/servers';

defineProps<{
    server: ServerSummary;
    /** On a share page (#5462): Start goes through the link. */
    shareToken?: string;
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
            <CopyAddress
                :address="server.address"
                :server-name="server.name"
                class="ml-1"
            />
        </p>
        <p
            v-if="server.address"
            class="text-sm text-muted-foreground"
            data-test="server-players"
        >
            <IdleStopNote :server="server" />
        </p>
        <p
            v-else-if="busy.includes(server.status)"
            class="text-sm text-muted-foreground"
        >
            <template
                v-if="
                    server.boot_elapsed_seconds !== null &&
                    server.typical_boot_seconds !== null
                "
            >
                <span data-test="server-boot-left"
                    ><BootEstimateNote :server="server"
                /></span>
                The page updates by itself.
            </template>
            <template v-else
                >This takes a few minutes. The page updates by itself.</template
            >
        </p>

        <p
            v-if="server.dns_error"
            class="text-sm text-amber-700 dark:text-amber-400"
            data-test="server-dns-error"
        >
            No name for this server: {{ server.dns_error }}
        </p>

        <p
            v-if="server.error"
            class="text-sm text-destructive"
            data-test="server-error"
        >
            {{ server.error_label }}: {{ server.error }}
        </p>

        <Form
            v-if="server.can_start"
            v-bind="
                shareToken
                    ? SharedServerController.start.form(shareToken)
                    : start.form(server.slug)
            "
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
            <span
                v-if="server.typical_boot_seconds !== null"
                class="ml-3 text-sm text-muted-foreground"
                data-test="server-boot-estimate"
                ><BootEstimateNote :server="server"
            /></span>
        </Form>

        <Form
            v-if="server.can_stop"
            v-bind="stop.form(server.slug)"
            :options="{ preserveScroll: true }"
            v-slot="{ processing }"
        >
            <Button
                type="submit"
                variant="outline"
                size="sm"
                :disabled="processing"
                :aria-label="`Stop ${server.name}`"
                >Stop</Button
            >
        </Form>
    </li>
</template>
