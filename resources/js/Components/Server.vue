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
                <dd v-if="ip !== '0.0.0.0'" class="text-gray-100 text-sm">
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
            <div v-if="status !== 'offline' && status !== 'online'">
                <div class="-mt-px flex divide-x divide-gray-200">
                    <div class="-ml-px mb-2 w-0 flex-1 flex">
                        <div
                            class="w-2/3 mx-auto pb-2 bg-gray-200 rounded-full h-2.5 dark:bg-gray-700"
                        >
                            <div
                                id="porbar"
                                class="bg-green-600 h-2.5 rounded-full"
                                style="width: 1%"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </li>
</template>

<script>
import axios from "axios";

export default {
    props: ["id", "name", "img", "ip", "status"],

    data() {
        return {
            id: this.id,
            name: this.name,
            img: this.img,
            ip: this.ip,
            status: this.status,
            progress: 0,
            timer: null,
            timer2: null,
        };
    },

    mounted() {
        axios.get(route("get-server", this.id)).then((response) => {
            if (response.data.error) {
                this.status = "offline";
            } else {
                this.status = response.data.status;
            }

            // // If status is online, display IP and turn to green
            // if (this.status === "online") {
            //     document
            //         .getElementById(this.id)
            //         .classList.remove("border-red-500");
            //     document
            //         .getElementById(this.id)
            //         .classList.add("border-green-500");
            //     this.ip = response.data.ip_address;
            // }

            // // If status isn't offline or online, then provision check
            // if (this.status === "offline" || this.status === "online") {
            //     return;
            // }

            // Setup timer which will check server status every 30 seconds.
            this.timer = setInterval(() => {
                console.log("ticker");

                if (this.status === "offline" || this.status === "online") {
                    // do nothing
                } else {
                    document
                        .getElementById(this.id)
                        .classList.remove("border-red-500");
                    document
                        .getElementById(this.id)
                        .classList.add("border-yellow-500");
                }

                // Get the server
                axios.get(route("get-server", this.id)).then((response) => {
                    // Check status and update accordingly
                    switch (response.data.status) {
                        case "provisioning":
                            document.getElementById("porbar").style.width =
                                "20%";
                            this.status = "provisioning";
                            break;
                        case "booting":
                            document.getElementById("porbar").style.width =
                                "40%";
                            this.status = "booting";
                            break;
                        case "installing":
                            document.getElementById("porbar").style.width =
                                "60%";
                            this.status = "installing";
                            break;
                        case "startup":
                            document.getElementById("porbar").style.width =
                                "80%";
                            this.status = "startup";
                            break;
                        case "online":
                            this.ip = response.data.ip_address;
                            document
                                .getElementById(this.id)
                                .classList.remove("border-yellow-500");
                            document
                                .getElementById(this.id)
                                .classList.add("border-green-500");
                            this.status = "online";
                            clearInterval(this.timer);
                            break;
                    }
                });
            }, 1000);
        });
    },

    methods: {
        startServer(id) {
            // Make URL call to provision the server
            axios.get(route("provision-server", id));

            // Update the status, border color & progress bar
            this.status = "provisioning";
            document.getElementById(id).classList.remove("border-red-500");
            document.getElementById(id).classList.add("border-yellow-500");

            // Setup timer which will check server status every 30 seconds.
            this.timer = setInterval(() => {
                console.log("ticker");
                // Get the server
                axios.get(route("get-server", this.id)).then((response) => {
                    // Check status and update accordingly
                    switch (response.data.status) {
                        case "provisioning":
                            document.getElementById("porbar").style.width =
                                "20%";
                            this.status = "provisioning";
                            break;
                        case "booting":
                            document.getElementById("porbar").style.width =
                                "40%";
                            this.status = "booting";
                            break;
                        case "installing":
                            document.getElementById("porbar").style.width =
                                "60%";
                            this.status = "installing";
                            break;
                        case "startup":
                            document.getElementById("porbar").style.width =
                                "80%";
                            this.status = "startup";
                            break;
                        case "online":
                            this.ip = response.data.ip_address;
                            document
                                .getElementById(id)
                                .classList.remove("border-yellow-500");
                            document
                                .getElementById(id)
                                .classList.add("border-green-500");
                            this.status = "online";
                            clearInterval(this.timer);
                            break;
                    }
                });
            }, 30000);
        },
    },
};
</script>
