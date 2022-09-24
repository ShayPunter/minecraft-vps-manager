<template>
    <li
        :id="id"
        class="col-span-1 flex flex-col text-center bg-gray-800 rounded-lg border-2 border-red-500 shadow"
    >
        <div class="flex-1 flex flex-col p-8">
            <img
                class="w-32 h-32 flex-shrink-0 mx-auto rounded-full"
                :src="img"
            />
            <h3 class="mt-6 text-gray-200 text-sm font-medium">{{ name }}</h3>
            <dl class="mt-1 flex-grow flex flex-col justify-between">
                <dd
                    v-if="ip !== '0.0.0.0'"
                    class="text-gray-100 text-sm"
                >
                    IP: {{ ip }}
                </dd>
            </dl>
        </div>
        <div>
            <div v-if="status === 'offline'">
                <div class="-mt-px flex divide-x divide-gray-200">
                    <div class="-ml-px w-0 flex-1 flex">
                        <a
                            @click="startServer(id)"
                            href="#"
                            class="relative w-0 flex-1 inline-flex items-center justify-center py-4 text-sm text-gray-200 font-medium border border-transparent rounded-br-lg hover:text-gray-100"
                        >
                            <span>Start Server</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </li>
</template>

<script>
export default {
    props: ['id', 'name', 'img', 'ip', 'status'],

    data() {
        return {
            startingserver: false,
            id: this.id,
            name: this.name,
            img: this.img,
            ip: this.ip,
            status: this.status
        }
    },

    methods: {
        startServer(id) {
            this.status = 'startup'
            this.startingserver = true

            setTimeout(() => {
                this.startingserver = false
            }, 5000)

            document.getElementById(id).classList.remove('border-red-500')
            document.getElementById(id).classList.add('border-yellow-500')

            setTimeout(() => {
                document.getElementById(id).classList.remove('border-yellow-500')
                document.getElementById(id).classList.add('border-green-500')

                this.status = 'online'
                this.ip = '80.245.21.23'
            }, 10000)
        },
    },
}
</script>
