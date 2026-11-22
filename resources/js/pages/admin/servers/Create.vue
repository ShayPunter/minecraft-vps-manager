<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import ServerController from '@/actions/App/Http/Controllers/Admin/ServerController';
import ServerFields from '@/components/admin/ServerFields.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/admin/servers';
import type { ServerCatalog } from '@/types/servers';

defineProps<{
    catalog: ServerCatalog;
    javaVersions: number[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Servers', href: index() },
            { title: 'New server', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="New server" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading
            title="New server"
            description="Nothing is created at Hetzner until someone presses Start."
        />

        <Form
            v-bind="ServerController.store.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <ServerFields
                :errors="errors"
                :catalog="catalog"
                :java-versions="javaVersions"
            />

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="save-server"
                    >Create server</Button
                >
                <Button variant="ghost" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
