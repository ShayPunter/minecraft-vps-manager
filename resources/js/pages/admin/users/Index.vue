<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import Heading from '@/components/Heading.vue';
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
import { create, edit, index } from '@/routes/admin/users';

type AdminUser = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'player';
    role_label: string;
    two_factor: boolean;
    created_at: string | null;
    can_delete: boolean;
};

defineProps<{
    users: AdminUser[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: index() }],
    },
});
</script>

<template>
    <Head title="Users" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Users"
                description="Sign-up is off: everyone who can log in has an account made here."
            />
            <Button as-child data-test="new-user">
                <Link :href="create()">New user</Link>
            </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border border-sidebar-border/70">
            <table class="w-full text-left text-sm" data-test="users-table">
                <thead class="border-b bg-muted/40 text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Email</th>
                        <th class="px-4 py-2 font-medium">Role</th>
                        <th class="px-4 py-2 font-medium">2FA</th>
                        <th class="px-4 py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users"
                        :key="user.id"
                        class="border-b last:border-0"
                        :data-test="`user-row-${user.email}`"
                    >
                        <td class="px-4 py-2 font-medium">{{ user.name }}</td>
                        <td class="px-4 py-2">{{ user.email }}</td>
                        <td class="px-4 py-2">
                            <Badge
                                :variant="
                                    user.role === 'admin'
                                        ? 'default'
                                        : 'secondary'
                                "
                                >{{ user.role_label }}</Badge
                            >
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ user.two_factor ? 'On' : 'Off' }}
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-2">
                                <Button variant="outline" size="sm" as-child>
                                    <Link
                                        :href="edit(user.id)"
                                        :aria-label="`Edit ${user.name}`"
                                        >Edit</Link
                                    >
                                </Button>
                                <Dialog v-if="user.can_delete">
                                    <DialogTrigger as-child>
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            :aria-label="`Delete ${user.name}`"
                                            >Delete</Button
                                        >
                                    </DialogTrigger>
                                    <DialogContent>
                                        <Form
                                            v-bind="
                                                UserController.destroy.form(
                                                    user.id,
                                                )
                                            "
                                            class="space-y-6"
                                            v-slot="{ processing }"
                                        >
                                            <DialogHeader class="space-y-3">
                                                <DialogTitle
                                                    >Delete
                                                    {{
                                                        user.name
                                                    }}?</DialogTitle
                                                >
                                                <DialogDescription>
                                                    {{ user.email }} won't be
                                                    able to log in any more.
                                                    This can't be undone.
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
                                                    data-test="confirm-delete-user"
                                                    >Delete user</Button
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
