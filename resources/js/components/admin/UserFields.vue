<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RoleOption = { value: string; label: string };

withDefaults(
    defineProps<{
        errors: Record<string, string | undefined>;
        roles: RoleOption[];
        name?: string;
        email?: string;
        role?: string;
        passwordRequired?: boolean;
        roleLocked?: boolean;
    }>(),
    {
        name: '',
        email: '',
        role: 'player',
        passwordRequired: true,
        roleLocked: false,
    },
);
</script>

<template>
    <div class="grid gap-2">
        <Label for="name">Name</Label>
        <Input
            id="name"
            name="name"
            :default-value="name"
            required
            autocomplete="off"
        />
        <InputError :message="errors.name" />
    </div>

    <div class="grid gap-2">
        <Label for="email">Email address</Label>
        <Input
            id="email"
            type="email"
            name="email"
            :default-value="email"
            required
            autocomplete="off"
        />
        <InputError :message="errors.email" />
    </div>

    <div class="grid gap-2">
        <Label for="role">Role</Label>
        <!-- :selected on the options, not :value on the select: Vue sets the value before the options exist. -->
        <select
            id="role"
            name="role"
            class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50 dark:bg-input/30"
            :disabled="roleLocked"
        >
            <option
                v-for="option in roles"
                :key="option.value"
                :value="option.value"
                :selected="option.value === role"
            >
                {{ option.label }}
            </option>
        </select>
        <!-- A disabled select isn't submitted: send the locked value instead. -->
        <input v-if="roleLocked" type="hidden" name="role" :value="role" />
        <p v-if="roleLocked" class="text-sm text-muted-foreground">
            You can't remove your own admin role.
        </p>
        <InputError :message="errors.role" />
    </div>

    <div class="grid gap-2">
        <Label for="password">{{
            passwordRequired ? 'Password' : 'New password'
        }}</Label>
        <PasswordInput
            id="password"
            name="password"
            :required="passwordRequired"
            autocomplete="new-password"
            :placeholder="passwordRequired ? '' : 'Leave blank to keep it'"
        />
        <InputError :message="errors.password" />
    </div>

    <div class="grid gap-2">
        <Label for="password_confirmation">Confirm password</Label>
        <PasswordInput
            id="password_confirmation"
            name="password_confirmation"
            :required="passwordRequired"
            autocomplete="new-password"
        />
        <InputError :message="errors.password_confirmation" />
    </div>
</template>
