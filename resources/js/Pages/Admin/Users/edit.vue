<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import { CheckCircleIcon, XMarkIcon } from "@heroicons/vue/20/solid";

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
    laravelVersion: String,
    phpVersion: String,
    editingUser: String,
});
</script>

<script>
import axios from "axios";

export default {
    data() {
        return {
            id: 0,
            name: "sample",
            email: "sample@sample.com",
            password: null,
            role: "default",
            api_token: null,
            success: false,
            successremove: false,
        };
    },

    mounted() {
        axios.get(route("get-auth-user")).then((res) => {
            this.api_token = res.data.api_token;
            axios
                .get(
                    route(
                        "api-get-user",
                        window.location.pathname.split("/")[4]
                    ),
                    {
                        params: {
                            api_token: this.api_token,
                        },
                    }
                )
                .then((response) => {
                    this.id = response.data.id;
                    this.name = response.data.name;
                    this.email = response.data.email;
                    this.role = response.data.role;
                });
        });
    },

    methods: {
        updateuser() {
            axios
                .post(route("api-edit-user", this.id), {
                    id: this.id,
                    name: this.name,
                    email: this.email,
                    password: this.password,
                    role: this.role,
                    api_token: this.api_token,
                })
                .then((res) => {
                    if (res.data === "success") {
                        this.success = true;
                    }
                });
        },

        deleteuser() {
            axios
                .post(route("api-delete-user", this.id), {
                    api_token: this.api_token,
                })
                .then((res) => {
                    if (res.data === "success") {
                        window.location.href = "../";
                    }
                });
        },
    },
};
</script>

<template>
    <AppLayout title="Dashboard">
        <template #header>
            <h1 class="text-2xl font-semibold text-gray-100">
                Editing User: {{ name }}
            </h1>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto">
                <div class="rounded-md bg-green-500 p-4 mb-4" v-if="success">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <CheckCircleIcon
                                class="h-5 w-5 text-gray-100"
                                aria-hidden="true"
                            />
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-100">
                                Successfully updated user
                            </p>
                        </div>
                        <div class="ml-auto pl-3">
                            <div class="-mx-1.5 -my-1.5">
                                <button
                                    type="button"
                                    @click="success = null"
                                    class="inline-flex rounded-md bg-green-600 p-1.5 text-gray-200 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-600 focus:ring-offset-2 focus:ring-offset-green-50"
                                >
                                    <span class="sr-only">Dismiss</span>
                                    <XMarkIcon
                                        class="h-5 w-5"
                                        aria-hidden="true"
                                    />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <form>
                    <div class="space-y-4">
                        <div>
                            <label
                                for="name"
                                class="block text-sm font-medium text-gray-300"
                                >Name</label
                            >
                            <div class="mt-1">
                                <input
                                    v-model="name"
                                    type="text"
                                    name="name"
                                    id="name"
                                    class="block w-full rounded-md text-gray-200 bg-gray-800 shadow-sm focus:border-blue-400 focus:ring-transparent sm:text-sm"
                                    placeholder="name person"
                                />
                            </div>
                        </div>

                        <div>
                            <label
                                for="email"
                                class="block text-sm font-medium text-gray-300"
                                >Email</label
                            >
                            <div class="mt-1">
                                <input
                                    v-model="email"
                                    type="email"
                                    name="email"
                                    id="email"
                                    class="block w-full rounded-md text-gray-200 bg-gray-800 shadow-sm focus:border-blue-400 focus:ring-transparent sm:text-sm"
                                    placeholder="you@example.com"
                                />
                            </div>
                        </div>

                        <div>
                            <label
                                for="password"
                                class="block text-sm font-medium text-gray-300"
                                >Set New Password (If unchanged, password will
                                remain the same)</label
                            >
                            <div class="mt-1">
                                <input
                                    type="password"
                                    name="password"
                                    v-model="password"
                                    id="password"
                                    class="block w-full rounded-md text-gray-200 bg-gray-800 shadow-sm focus:border-blue-400 focus:ring-transparent sm:text-sm"
                                    placeholder="password"
                                />
                            </div>
                        </div>

                        <div>
                            <label
                                for="role"
                                class="block text-sm font-medium text-gray-300"
                                >Role</label
                            >
                            <div class="mt-1">
                                <select
                                    id="role"
                                    v-model="role"
                                    name="role"
                                    class="block w-full max-w-lg rounded-md text-gray-200 bg-gray-800 shadow-sm focus:border-blue-400 focus:ring-transparent sm:max-w-xs sm:text-sm"
                                >
                                    <option value="default">Default</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center rounded-md border border-transparent bg-gray-700 px-4 py-2 text-sm font-medium text-gray-200 shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-none"
                            @click="updateuser()"
                        >
                            Update User
                        </button>
                        <button
                            type="button"
                            class="inline-flex ml-64 items-center rounded-md border border-transparent bg-red-700 px-4 py-2 text-sm font-medium text-gray-200 shadow-sm hover:bg-red-600 focus:outline-none focus:ring-none"
                            @click="deleteuser()"
                        >
                            Delete User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
