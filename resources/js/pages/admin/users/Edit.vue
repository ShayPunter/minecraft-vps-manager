<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import UserFields from '@/components/admin/UserFields.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/admin/users';

defineProps<{
    editing: {
        id: number;
        name: string;
        email: string;
        role: string;
        is_self: boolean;
    };
    roles: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: index() }],
    },
});
</script>

<template>
    <Head :title="`Edit ${editing.name}`" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading :title="`Edit ${editing.name}`" />

        <Form
            v-bind="UserController.update.form(editing.id)"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <UserFields
                :errors="errors"
                :roles="roles"
                :name="editing.name"
                :email="editing.email"
                :role="editing.role"
                :password-required="false"
                :role-locked="editing.is_self"
            />

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="save-user"
                    >Save</Button
                >
                <Button variant="ghost" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
