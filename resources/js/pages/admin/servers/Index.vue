<script setup lang="ts">
import { Form, Head, Link, usePoll } from '@inertiajs/vue3';
import ServerController from '@/actions/App/Http/Controllers/Admin/ServerController';
import ServerLogController from '@/actions/App/Http/Controllers/Admin/ServerLogController';
import CopyAddress from '@/components/CopyAddress.vue';
import Heading from '@/components/Heading.vue';
import IdleStopNote from '@/components/IdleStopNote.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { create, edit, index } from '@/routes/admin/servers';
import type { ServerSummary } from '@/types/servers';

defineProps<{
    servers: ServerSummary[];
    maxRunning: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Servers', href: index() }],
    },
});

usePoll(5000, { only: ['servers'] });
</script>

<template>
    <Head title="Servers" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Servers"
                :description="`At most ${maxRunning} run at once. Each one's VPS is created on Start and deleted on Stop.`"
            />
            <Button as-child data-test="new-server">
                <Link :href="create()">New server</Link>
            </Button>
        </div>

        <div
            v-if="servers.length === 0"
            class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            No servers yet.
        </div>

        <div
            v-else
            class="overflow-x-auto rounded-xl border border-sidebar-border/70"
        >
            <table class="w-full text-left text-sm" data-test="servers-table">
                <thead class="border-b bg-muted/40 text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Visibility</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="server in servers"
                        :key="server.id"
                        class="border-b last:border-0"
                        :data-test="`server-row-${server.slug}`"
                    >
                        <td class="px-4 py-2">
                            <p class="font-medium">{{ server.name }}</p>
                            <p class="font-mono text-xs text-muted-foreground">
                                {{ server.slug }}
                            </p>
                        </td>
                        <td class="px-4 py-2">
                            {{ server.is_public ? 'Public' : 'Private' }}
                        </td>
                        <td class="px-4 py-2">
                            <Badge
                                :variant="
                                    server.status === 'online'
                                        ? 'default'
                                        : 'secondary'
                                "
                                >{{ server.status_label }}</Badge
                            >
                            <span
                                v-if="server.address"
                                class="ml-2 font-mono text-xs"
                                >{{ server.address }}</span
                            >
                            <CopyAddress
                                v-if="server.address"
                                :address="server.address"
                                :server-name="server.name"
                                class="ml-1"
                            />
                            <p
                                v-if="server.status === 'online'"
                                class="mt-1 text-xs text-muted-foreground"
                                data-test="server-idle"
                            >
                                <IdleStopNote :server="server" />
                            </p>
                            <p
                                v-if="server.error"
                                class="mt-1 max-w-md text-xs text-destructive"
                                data-test="server-error"
                            >
                                {{ server.error_label }}: {{ server.error }}
                            </p>
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-2">
                                <Button
                                    as-child
                                    variant="ghost"
                                    size="sm"
                                    :data-test="`log-${server.slug}`"
                                >
                                    <Link
                                        :href="
                                            ServerLogController.show(
                                                server.slug,
                                            )
                                        "
                                        :aria-label="`Log of ${server.name}`"
                                        >Log</Link
                                    >
                                </Button>
                                <Form
                                    v-if="server.can_stop"
                                    v-bind="
                                        ServerController.stop.form(server.slug)
                                    "
                                    :options="{ preserveScroll: true }"
                                    v-slot="{ processing }"
                                >
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        :disabled="processing"
                                        :aria-label="`Stop ${server.name}`"
                                        >{{
                                            server.status === 'stop_failed'
                                                ? 'Retry stop'
                                                : 'Stop'
                                        }}</Button
                                    >
                                </Form>
                                <Dialog v-if="server.can_force_delete">
                                    <DialogTrigger as-child>
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            :aria-label="`Delete the VPS of ${server.name} without saving`"
                                            >Delete without saving</Button
                                        >
                                    </DialogTrigger>
                                    <DialogContent>
                                        <Form
                                            v-bind="
                                                ServerController.stop.form(
                                                    server.slug,
                                                )
                                            "
                                            class="space-y-6"
                                            v-slot="{ processing }"
                                        >
                                            <input
                                                type="hidden"
                                                name="force"
                                                value="1"
                                            />
                                            <DialogHeader class="space-y-3">
                                                <DialogTitle
                                                    >Delete the VPS without
                                                    saving?</DialogTitle
                                                >
                                                <DialogDescription>
                                                    Everything played since
                                                    {{ server.name }} started is
                                                    lost. Only do this when the
                                                    VPS is broken and "Retry
                                                    stop" keeps failing.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <DialogFooter class="gap-2">
                                                <DialogClose as-child>
                                                    <Button variant="secondary"
                                                        >Cancel</Button
                                                    >
                                                </DialogClose>
                                                <Button
                                                    type="submit"
                                                    variant="destructive"
                                                    :disabled="processing"
                                                    data-test="confirm-force-delete"
                                                    >Delete the VPS</Button
                                                >
                                            </DialogFooter>
                                        </Form>
                                    </DialogContent>
                                </Dialog>
                                <Button variant="outline" size="sm" as-child>
                                    <Link
                                        :href="edit(server.slug)"
                                        :aria-label="`Edit ${server.name}`"
                                        >Edit</Link
                                    >
                                </Button>
                                <Dialog v-if="server.can_start">
                                    <DialogTrigger as-child>
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            :aria-label="`Delete ${server.name}`"
                                            >Delete</Button
                                        >
                                    </DialogTrigger>
                                    <DialogContent>
                                        <Form
                                            v-bind="
                                                ServerController.destroy.form(
                                                    server.slug,
                                                )
                                            "
                                            class="space-y-6"
                                            v-slot="{ processing }"
                                        >
                                            <DialogHeader class="space-y-3">
                                                <DialogTitle
                                                    >Delete
                                                    {{
                                                        server.name
                                                    }}?</DialogTitle
                                                >
                                                <DialogDescription>
                                                    The server's settings and
                                                    run history go. Its saved
                                                    worlds stay in storage
                                                    unless you tick the box.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <input
                                                    :id="`delete_archives_${server.slug}`"
                                                    type="checkbox"
                                                    name="delete_archives"
                                                    value="1"
                                                    class="size-4"
                                                />
                                                <label
                                                    :for="`delete_archives_${server.slug}`"
                                                    class="text-sm"
                                                    >Also delete its saved
                                                    worlds from storage (can't
                                                    be undone)</label
                                                >
                                            </div>
                                            <DialogFooter class="gap-2">
                                                <DialogClose as-child>
                                                    <Button variant="secondary"
                                                        >Cancel</Button
                                                    >
                                                </DialogClose>
                                                <Button
                                                    type="submit"
                                                    variant="destructive"
                                                    :disabled="processing"
                                                    data-test="confirm-delete-server"
                                                    >Delete server</Button
                                                >
                                            </DialogFooter>
                                        </Form>
                                    </DialogContent>
                                </Dialog>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
