<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import ServerController from '@/actions/App/Http/Controllers/Admin/ServerController';
import ServerFields from '@/components/admin/ServerFields.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/admin/servers';
import type { ServerCatalog, ServerDefinition } from '@/types/servers';

const props = defineProps<{
    server: ServerDefinition;
    catalog: ServerCatalog;
    javaVersions: number[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Servers', href: index() }],
    },
});
</script>

<template>
    <Head :title="`Edit ${props.server.name}`" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading
            :title="`Edit ${props.server.name}`"
            description="Changes apply from the next start."
        />

        <Form
            v-bind="ServerController.update.form(props.server.slug)"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <ServerFields
                :errors="errors"
                :catalog="catalog"
                :java-versions="javaVersions"
                :server="props.server"
            />

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="save-server"
                    >Save</Button
                >
                <Button variant="ghost" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
