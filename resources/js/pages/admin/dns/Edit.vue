<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import DnsController from '@/actions/App/Http/Controllers/Admin/DnsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/admin/dns';

type DnsSettings = {
    domain: string;
    zone_id: string;
    has_token: boolean;
    updated_at: string | null;
};

const props = defineProps<{
    dns: DnsSettings;
    problem: string | null;
    servers: { name: string; slug: string; address: string | null }[];
    records: { name: string; content: string }[] | null;
    recordsError: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'DNS', href: edit() }],
    },
});

// In the script, not the template: vue-tsc 3.3.13 misreports templates with https:// attributes.
const tokenUrl = 'https://dash.cloudflare.com/profile/api-tokens';

const form = useForm({
    domain: props.dns.domain,
    zone_id: props.dns.zone_id,
    api_token: '',
});

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function save(): void {
    form.put(DnsController.update.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset('api_token'),
    });
}
</script>

<template>
    <Head title="DNS" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading
            title="DNS"
            description="Give each server a name, like atm9.example.com. While a server runs, the name points at its VPS, so players don't need the IP. The record is deleted again when the server stops."
        />

        <div
            class="space-y-1 rounded-xl border border-sidebar-border/70 p-4 text-sm"
            data-test="dns-status"
        >
            <p v-if="problem" class="text-destructive" data-test="dns-problem">
                {{ problem }}
            </p>
            <p v-else class="text-green-700 dark:text-green-400">
                Ready: servers with a subdomain get their name when they start.
            </p>
            <template v-if="servers.length > 0">
                <p class="font-medium">Servers with a name:</p>
                <ul class="list-disc pl-5" data-test="dns-servers">
                    <li v-for="server in servers" :key="server.slug">
                        {{ server.name }}:
                        <span class="font-mono">{{
                            server.address ?? '(set up DNS first)'
                        }}</span>
                    </li>
                </ul>
            </template>
            <p v-else class="text-muted-foreground">
                No server has a subdomain yet: set one in Admin → Servers.
            </p>
            <template v-if="records !== null">
                <p class="font-medium">In Cloudflare now:</p>
                <ul
                    v-if="records.length > 0"
                    class="list-disc pl-5"
                    data-test="dns-records"
                >
                    <li v-for="record in records" :key="record.name">
                        <span class="font-mono"
                            >{{ record.name }} → {{ record.content }}</span
                        >
                    </li>
                </ul>
                <p
                    v-else
                    class="text-muted-foreground"
                    data-test="dns-no-records"
                >
                    No records: no named server is running.
                </p>
            </template>
            <p
                v-if="recordsError"
                class="text-destructive"
                data-test="dns-records-error"
            >
                Couldn't list the records: {{ recordsError }}
            </p>
        </div>

        <form class="space-y-6" @submit.prevent="save">
            <div class="grid gap-2">
                <Label for="domain">Domain</Label>
                <Input
                    id="domain"
                    v-model="form.domain"
                    name="domain"
                    placeholder="example.com"
                    autocomplete="off"
                    required
                />
                <InputError :message="errors.domain" />
            </div>

            <div class="grid gap-2">
                <Label for="zone_id">Zone ID</Label>
                <Input
                    id="zone_id"
                    v-model="form.zone_id"
                    name="zone_id"
                    class="font-mono"
                    autocomplete="off"
                    required
                />
                <p class="text-sm text-muted-foreground">
                    On the domain's Overview page in Cloudflare (API section,
                    bottom right).
                </p>
                <InputError :message="errors.zone_id" />
            </div>

            <div class="grid gap-2">
                <Label for="api_token">API token</Label>
                <Input
                    id="api_token"
                    v-model="form.api_token"
                    name="api_token"
                    type="password"
                    autocomplete="new-password"
                    :placeholder="
                        dns.has_token ? 'Saved: leave empty to keep it' : ''
                    "
                />
                <p class="text-sm text-muted-foreground">
                    Make one at
                    <a
                        :href="tokenUrl"
                        target="_blank"
                        rel="noopener"
                        class="underline"
                        >My Profile → API Tokens</a
                    >
                    with the "Edit zone DNS" template, for this domain only.
                </p>
                <InputError :message="errors.api_token" />
            </div>

            <p
                v-if="errors.connection"
                class="text-sm text-destructive"
                data-test="dns-error"
            >
                {{ errors.connection }}
            </p>

            <Button
                type="submit"
                :disabled="form.processing"
                data-test="save-dns"
                >Test and save</Button
            >
            <p class="text-sm text-muted-foreground">
                Saving makes and deletes a test record first, and only saves
                when that works. The token is stored encrypted and never shown
                again. Records the app makes say so in their comment; records
                you made yourself are never changed.
            </p>
        </form>
    </div>
</template>
