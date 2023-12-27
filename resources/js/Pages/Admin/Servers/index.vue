<script>
import axios from "axios";

export default {
    data() {
        return {
            servers: [],
        };
    },

    mounted() {
        axios.get(route("get-auth-user")).then((res) => {
            var api = res.data.api_token;
            axios
                .get(route("api-get-users"), {
                    params: {
                        api_token: api,
                    },
                })
                .then((response) => {
                    this.people = response.data;
                });
        });
    },
};
</script>

<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
</script>

<template>
    <AppLayout title="Dashboard">
        <template #header>
            <h1 class="text-2xl font-semibold text-gray-100 inline-flex">
                Users
            </h1>
            <a
                :href="route('create-user')"
                class="ml-12 inline-flex items-center rounded-md border border-transparent bg-gray-700 px-4 py-2 text-sm font-medium text-gray-200 shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-none"
            >
                Create User
            </a>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto">
                <div class="mt-8 flex flex-col">
                    <div class="-my-2 -mx-4 overflow-x-auto sm:-mx-6 lg:-mx-8">
                        <div
                            class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8"
                        >
                            <div
                                class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg"
                            >
                                <table
                                    class="min-w-full divide-y divide-gray-600"
                                >
                                    <thead class="bg-gray-800">
                                        <tr>
                                            <th
                                                scope="col"
                                                class="py-3 pl-4 pr-3 text-left text-xs font-medium uppercase tracking-wide text-gray-300 sm:pl-6"
                                            >
                                                Name
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-300"
                                            >
                                                Email
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-300"
                                            >
                                                Role
                                            </th>
                                            <th
                                                scope="col"
                                                class="relative py-3 pl-3 pr-4 sm:pr-6"
                                            >
                                                <span class="sr-only"
                                                    >Edit</span
                                                >
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody
                                        class="divide-y divide-gray-600 bg-gray-700"
                                    >
                                        <tr
                                            v-for="person in people"
                                            :key="person.email"
                                        >
                                            <td
                                                class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-300 sm:pl-6"
                                            >
                                                {{ person.name }}
                                            </td>
                                            <td
                                                class="whitespace-nowrap px-3 py-4 text-sm text-gray-300"
                                            >
                                                {{ person.email }}
                                            </td>
                                            <td
                                                class="whitespace-nowrap px-3 py-4 text-sm text-gray-300"
                                            >
                                                {{ person.role }}
                                            </td>
                                            <td
                                                class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6"
                                            >
                                                <a
                                                    :href="
                                                        route(
                                                            'edit-user',
                                                            person.id
                                                        )
                                                    "
                                                    class="text-blue-500 hover:text-blue-600"
                                                    >Edit<span class="sr-only"
                                                        >,\
                                                        {{ person.name }}</span
                                                    ></a
                                                >
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
