<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import UserFields from '@/components/admin/UserFields.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/admin/users';

defineProps<{
    roles: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: index() },
            { title: 'New user', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="New user" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading
            title="New user"
            description="They log in with this email and password. Tell them the password yourself; no email is sent."
        />

        <Form
            v-bind="UserController.store.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <UserFields :errors="errors" :roles="roles" />

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="save-user"
                    >Create user</Button
                >
                <Button variant="ghost" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
