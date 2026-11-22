<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import WorldStorageController from '@/actions/App/Http/Controllers/Admin/WorldStorageController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/admin/world-storage';

type StorageSettings = {
    source: 'database' | 'env' | null;
    driver: 'sftp' | 'ftp' | 's3';
    host: string;
    port: number;
    username: string;
    root: string;
    ftps: boolean;
    endpoint: string;
    region: string;
    bucket: string;
    path_style: boolean;
    has_password: boolean;
    has_private_key: boolean;
    host_key_fingerprint: string | null;
    updated_at: string | null;
};

const props = defineProps<{
    storage: StorageSettings;
    problem: string | null;
    fake: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'World storage', href: edit() }],
    },
});

const form = useForm({
    driver: props.storage.driver,
    host: props.storage.host,
    port: props.storage.port,
    username: props.storage.username,
    password: '',
    private_key: '',
    root: props.storage.root,
    ftps: props.storage.ftps,
    endpoint: props.storage.endpoint,
    region: props.storage.region,
    bucket: props.storage.bucket,
    path_style: props.storage.path_style,
    remove_password: false,
    remove_private_key: false,
    trust_new_host_key: false,
});

const defaultPorts = { sftp: 22, ftp: 21, s3: 443 } as const;

const isS3 = computed(() => form.driver === 's3');

// In the script, not the template: vue-tsc 3.3.13 misreads "https://" in a template attribute.
const endpointPlaceholder = 'https://fsn1.your-objectstorage.com';

// Switching type moves a default port along with it, and leaves a custom one alone.
watch(
    () => form.driver,
    (driver, previous) => {
        if (previous && form.port === defaultPorts[previous]) {
            form.port = defaultPorts[driver];
        }
    },
);

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30';

function save(): void {
    form.put(WorldStorageController.update.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset('password', 'private_key'),
    });
}

function testConnection(): void {
    form.post(WorldStorageController.test.url(), { preserveScroll: true });
}

const sourceLabel = computed(() => {
    if (props.storage.source === 'database') {
        return 'Saved here';
    }

    if (props.storage.source === 'env') {
        return 'From the env (WORLD_STORAGE_*): save it here to pin the host key and manage it from this page';
    }

    return 'Not set up';
});
</script>

<template>
    <Head title="World storage" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading
            title="World storage"
            description="The SFTP, FTP or S3-compatible storage where worlds live while no VPS is running. Each start downloads the server's world from here, and each stop uploads it again."
        />

        <div
            class="space-y-1 rounded-xl border border-sidebar-border/70 p-4 text-sm"
            data-test="storage-status"
        >
            <p><span class="font-medium">Settings:</span> {{ sourceLabel }}</p>
            <p v-if="storage.host_key_fingerprint">
                <span class="font-medium">Host key:</span>
                <span class="font-mono" data-test="host-key">{{
                    storage.host_key_fingerprint
                }}</span>
            </p>
            <p
                v-if="problem"
                class="text-destructive"
                data-test="storage-problem"
            >
                {{ problem }}
            </p>
            <p v-else class="text-green-700 dark:text-green-400">
                Ready: starts and stops use these settings.
            </p>
            <p v-if="fake" class="text-muted-foreground">
                Test mode: worlds go to a local folder, no connection is made.
            </p>
        </div>

        <form class="space-y-6" @submit.prevent="save">
            <div class="grid gap-2">
                <Label for="driver">Type</Label>
                <select
                    id="driver"
                    v-model="form.driver"
                    name="driver"
                    :class="selectClass"
                >
                    <option value="sftp">SFTP (SSH)</option>
                    <option value="ftp">FTP</option>
                    <option value="s3">S3-compatible</option>
                </select>
                <InputError :message="errors.driver" />
            </div>

            <template v-if="isS3">
                <div class="grid gap-2">
                    <Label for="endpoint">Endpoint</Label>
                    <Input
                        id="endpoint"
                        v-model="form.endpoint"
                        name="endpoint"
                        autocomplete="off"
                        :placeholder="endpointPlaceholder"
                    />
                    <p class="text-sm text-muted-foreground">
                        Leave empty for AWS. Hetzner Object Storage, Cloudflare
                        R2, Backblaze B2 and MinIO each give you one.
                    </p>
                    <InputError :message="errors.endpoint" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="region">Region</Label>
                        <Input
                            id="region"
                            v-model="form.region"
                            name="region"
                            required
                            autocomplete="off"
                            placeholder="eu-central-1"
                        />
                        <InputError :message="errors.region" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="bucket">Bucket</Label>
                        <Input
                            id="bucket"
                            v-model="form.bucket"
                            name="bucket"
                            required
                            autocomplete="off"
                        />
                        <InputError :message="errors.bucket" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center gap-2">
                        <input
                            id="path_style"
                            v-model="form.path_style"
                            type="checkbox"
                            class="size-4"
                        />
                        <Label for="path_style"
                            >Path-style URLs (bucket in the path)</Label
                        >
                    </div>
                    <p class="text-sm text-muted-foreground">
                        On for MinIO and other self-hosted servers. Off for AWS,
                        Hetzner, R2 and B2.
                    </p>
                </div>
            </template>

            <div v-else class="grid grid-cols-[1fr_7rem] gap-4">
                <div class="grid gap-2">
                    <Label for="host">Host</Label>
                    <Input
                        id="host"
                        v-model="form.host"
                        name="host"
                        required
                        autocomplete="off"
                        placeholder="u123456.your-storagebox.de"
                    />
                    <InputError :message="errors.host" />
                </div>
                <div class="grid gap-2">
                    <Label for="port">Port</Label>
                    <Input
                        id="port"
                        v-model.number="form.port"
                        name="port"
                        type="number"
                        min="1"
                        max="65535"
                        required
                    />
                    <InputError :message="errors.port" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="username">{{ isS3 ? 'Access key' : 'User' }}</Label>
                <Input
                    id="username"
                    v-model="form.username"
                    name="username"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.username" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{
                    isS3 ? 'Secret key' : 'Password'
                }}</Label>
                <Input
                    id="password"
                    v-model="form.password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    :placeholder="
                        storage.has_password
                            ? 'Saved: leave empty to keep it'
                            : ''
                    "
                />
                <div
                    v-if="storage.has_password"
                    class="flex items-center gap-2"
                >
                    <input
                        id="remove_password"
                        v-model="form.remove_password"
                        type="checkbox"
                        class="size-4"
                    />
                    <Label for="remove_password"
                        >Remove the saved
                        {{ isS3 ? 'secret key' : 'password' }}</Label
                    >
                </div>
                <InputError :message="errors.password" />
            </div>

            <div v-if="form.driver === 'sftp'" class="grid gap-2">
                <Label for="private_key">Private key</Label>
                <textarea
                    id="private_key"
                    v-model="form.private_key"
                    name="private_key"
                    rows="4"
                    autocomplete="off"
                    spellcheck="false"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30"
                    :placeholder="
                        storage.has_private_key
                            ? 'Saved: leave empty to keep it'
                            : '-----BEGIN OPENSSH PRIVATE KEY-----'
                    "
                />
                <p class="text-sm text-muted-foreground">
                    Optional, instead of a password. Unencrypted OpenSSH or PEM.
                </p>
                <div
                    v-if="storage.has_private_key"
                    class="flex items-center gap-2"
                >
                    <input
                        id="remove_private_key"
                        v-model="form.remove_private_key"
                        type="checkbox"
                        class="size-4"
                    />
                    <Label for="remove_private_key"
                        >Remove the saved private key</Label
                    >
                </div>
                <InputError :message="errors.private_key" />
            </div>

            <div class="grid gap-2">
                <Label for="root">{{
                    isS3 ? 'Folder prefix (optional)' : 'Base folder'
                }}</Label>
                <Input
                    id="root"
                    v-model="form.root"
                    name="root"
                    :required="!isS3"
                    autocomplete="off"
                    class="font-mono"
                />
                <p class="text-sm text-muted-foreground">
                    Each server's worlds go in a folder named after its slug
                    inside this one.
                    <template v-if="isS3"
                        >The VPS only ever gets short-lived pre-signed links,
                        never these keys.</template
                    >
                </p>
                <InputError :message="errors.root" />
            </div>

            <div v-if="form.driver === 'ftp'" class="grid gap-2">
                <div class="flex items-center gap-2">
                    <input
                        id="ftps"
                        v-model="form.ftps"
                        type="checkbox"
                        class="size-4"
                    />
                    <Label for="ftps">Use TLS (FTPS)</Label>
                </div>
                <p
                    v-if="!form.ftps"
                    class="text-sm text-destructive"
                    data-test="plain-ftp-warning"
                >
                    Plain FTP sends the password and the worlds unencrypted.
                </p>
            </div>

            <div
                v-if="form.driver === 'sftp' && storage.host_key_fingerprint"
                class="flex items-center gap-2"
            >
                <input
                    id="trust_new_host_key"
                    v-model="form.trust_new_host_key"
                    type="checkbox"
                    class="size-4"
                />
                <Label for="trust_new_host_key"
                    >Trust the new host key (only after moving to another
                    storage server)</Label
                >
            </div>

            <p
                v-if="errors.connection"
                class="text-sm text-destructive"
                data-test="connection-error"
            >
                {{ errors.connection }}
            </p>

            <div class="flex flex-wrap items-center gap-4">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="save-storage"
                    >Test and save</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    data-test="test-storage"
                    @click="testConnection"
                    >Test connection</Button
                >
            </div>
            <p class="text-sm text-muted-foreground">
                Saving tests the connection first and only saves when it works.
                The password and key are stored encrypted and never shown again.
            </p>
        </form>
    </div>
</template>
