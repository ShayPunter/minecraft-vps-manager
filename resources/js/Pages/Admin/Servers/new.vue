<script>
import AppLayout from "../../../Layouts/AppLayout.vue";

export default {
    components: {AppLayout},
    data() {
        return {
            csrfToken: '',
            errors: {},
            provider: 'linode',
            serverTypes: [],
        }
    },
    async mounted() {
        this.csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        this.errors = this.$page.props.errors || {};
        this.fetchLinodeTypes();
    },
    methods: {
        async fetchLinodeTypes() {
            try {
                const response = await axios.get('/api/linode-types');
                this.serverTypes = response.data;
            } catch (error) {
                console.error('Error fetching server types:', error);
            }
        }
    }
}
</script>

<template>
    <AppLayout title="Dashboard">
        <template #header>
            <h1 class="text-2xl font-semibold text-gray-100">
                Create new server
            </h1>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto">

                <div v-if="Object.keys(errors).length > 0" class="rounded-md bg-red-500 p-4 mb-4">
                    <ul>
                        <li v-for="(error, field) in errors" :key="field" class="text-sm font-medium text-gray-100">
                            {{ error }}
                        </li>
                    </ul>
                </div>

                <form :action="route('api-server-create')" method="POST" enctype="multipart/form-data">
                    <input
                        type="hidden"
                        name="_token"
                        :value="csrfToken"
                        class="block w-full rounded-md text-gray-200 bg-gray-800 shadow-sm focus:border-blue-400 focus:ring-transparent sm:text-sm"
                    />

                    <div class="space-y-4">
                        <div>
                            <label
                                for="name"
                                class="block text-sm font-medium text-gray-300"
                                >Name</label
                            >
                            <div class="mt-1">
                                <input
                                    type="text"
                                    name="name"
                                    id="name"
                                    class="block w-full rounded-md text-gray-200 bg-gray-800 shadow-sm focus:border-blue-400 focus:ring-transparent sm:text-sm"
                                    placeholder="server name"
                                />
                            </div>
                        </div>

                        <div>
                            <label
                                for="provider"
                                class="block text-sm font-medium text-gray-300"
                            >Server Provider</label
                            >
                            <div class="mt-1">
                                <select
                                    id="provider"
                                    name="provider"
                                    v-model="provider"
                                    class="block w-full max-w-lg rounded-md text-gray-200 bg-gray-800 shadow-sm focus:border-blue-400 focus:ring-transparent sm:max-w-xs sm:text-sm"
                                >
                                    <option value="linode">Linode</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label
                                for="size"
                                class="block text-sm font-medium text-gray-300"
                            >Server Size</label
                            >
                            <div class="mt-1">
                                <select
                                    v-if="provider === 'linode'"
                                    id="type"
                                    name="server_size"
                                    class="block w-full max-w-lg rounded-md text-gray-200 bg-gray-800 shadow-sm focus:border-blue-400 focus:ring-transparent sm:max-w-xs sm:text-sm"
                                >
                                    <option v-for="type in serverTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
                                </select>

                                <select
                                    v-if="provider === 'hetzner'"
                                    id="type"
                                    name="server_size"
                                    class="block w-full max-w-lg rounded-md text-gray-200 bg-gray-800 shadow-sm focus:border-blue-400 focus:ring-transparent sm:max-w-xs sm:text-sm"
                                >
                                    <option value="nanode">Nanode / 1 vCPU / 1GB RAM / 25GB Storage</option>
                                </select>
                            </div>
                        </div>

                        <div>

                            <label
                                for="size"
                                class="block text-sm font-medium text-gray-300"
                            >Server File</label
                            >
                            <div class="mt-1">
                                <input
                                    type="file"
                                    name="file"
                                    class="block w-full rounded-md text-gray-200 bg-gray-800 shadow-sm focus:border-blue-400 focus:ring-transparent sm:text-sm"
                                />
                            </div>
                        </div>

                        <div>

                            <label
                                for="size"
                                class="block text-sm font-medium text-gray-300"
                            >Server Icon (for panel, not in game)</label
                            >
                            <div class="mt-1">
                                <input
                                    type="file"
                                    name="server_icon"
                                    class="block w-full rounded-md text-gray-200 bg-gray-800 shadow-sm focus:border-blue-400 focus:ring-transparent sm:text-sm"
                                />
                            </div>
                        </div>

                        <div>

                            <label
                                for="size"
                                class="block text-sm font-medium text-gray-300"
                            >Is Public (users can turn on the server without a login)</label
                            >
                            <div class="mt-1">
                                <input
                                    type="checkbox"
                                    name="is_public"
                                />
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-md border border-transparent bg-gray-700 px-4 py-2 text-sm font-medium text-gray-200 shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-none"
                        >
                            Create Server
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
