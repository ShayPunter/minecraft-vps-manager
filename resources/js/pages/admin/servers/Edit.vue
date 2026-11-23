<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import ServerController from '@/actions/App/Http/Controllers/Admin/ServerController';
import WorldArchiveController from '@/actions/App/Http/Controllers/Admin/WorldArchiveController';
import CopyAddress from '@/components/CopyAddress.vue';
import InputError from '@/components/InputError.vue';
import ServerConfigFields from '@/components/admin/ServerConfigFields.vue';
import ServerFields from '@/components/admin/ServerFields.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/admin/servers';
import { edit as worldStorage } from '@/routes/admin/world-storage';
import type {
    GuestStartLimits,
    ServerCatalog,
    ServerConfig,
    ServerDefinition,
    SoftwareOptions,
} from '@/types/servers';

const props = defineProps<{
    server: ServerDefinition;
    catalog: ServerCatalog;
    javaVersions: number[];
    software: SoftwareOptions;
    config: ServerConfig;
    guestStarts: GuestStartLimits;
    dnsDomain: string | null;
    wakerAddress: string | null;
    world: {
        archives: {
            id: number;
            file_name: string;
            size: number;
            sha256: string;
            source: 'upload' | 'save';
            uploaded_by: string | null;
            created_at: string | null;
            current: boolean;
        }[];
        storageProblem: string | null;
        running: boolean;
        uploadMaxMb: number;
        keep: number;
    };
}>();

function formatSize(bytes: number): string {
    if (bytes >= 1024 ** 3) {
        return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
    }

    if (bytes >= 1024 ** 2) {
        return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '';
}

function describe(archive: {
    source: string;
    uploaded_by: string | null;
}): string {
    if (archive.source !== 'upload') {
        return 'saved on stop';
    }

    return archive.uploaded_by
        ? `uploaded by ${archive.uploaded_by}`
        : 'uploaded';
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Servers', href: index() }],
    },
});
</script>

<template>
    <Head :title="`Edit ${props.server.name}`" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading
            :title="`Edit ${props.server.name}`"
            description="Changes apply from the next start."
        />

        <Form
            v-bind="ServerController.update.form(props.server.slug)"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <ServerFields
                :errors="errors"
                :catalog="catalog"
                :java-versions="javaVersions"
                :software="software"
                :dns-domain="dnsDomain"
                :waker-address="wakerAddress"
                :server="props.server"
                :has-saved-world="world.archives.some((a) => a.current)"
                :guest-starts="guestStarts"
            />
            <ServerConfigFields
                :errors="errors"
                :config="config"
                :running="world.running"
            />

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="save-server"
                    >Save</Button
                >
                <Button variant="ghost" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>

        <section
            v-if="props.server.share_url"
            class="space-y-3 border-t pt-6"
            data-test="share-section"
        >
            <Heading
                variant="small"
                title="Share link"
                description="Anyone with this link can see and start this server, without an account. New link turns the old one off at once."
            />
            <p class="flex flex-wrap items-center gap-1 text-sm">
                <code
                    class="rounded bg-muted px-1 py-0.5 font-mono break-all"
                    data-test="share-url"
                    >{{ props.server.share_url }}</code
                >
                <CopyAddress
                    :address="props.server.share_url"
                    :server-name="props.server.name"
                    :label="`Copy the share link of ${props.server.name}`"
                />
            </p>
            <Form
                v-bind="
                    ServerController.regenerateShareLink.form(props.server.slug)
                "
                :options="{ preserveScroll: true }"
                v-slot="{ processing }"
            >
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    :disabled="processing"
                    data-test="new-share-link"
                    >New link</Button
                >
            </Form>
        </section>

        <section class="space-y-4 border-t pt-6" data-test="world-section">
            <Heading
                variant="small"
                title="World"
                :description="`The newest ${props.world.keep} archives are kept. The current one is what the next start downloads.`"
            />

            <p
                v-if="props.world.storageProblem"
                class="text-sm text-destructive"
                data-test="world-storage-problem"
            >
                {{ props.world.storageProblem }}
                <Link :href="worldStorage()" class="underline"
                    >Open World storage</Link
                >
            </p>

            <p
                v-if="props.world.archives.length === 0"
                class="text-sm text-muted-foreground"
                data-test="no-archives"
            >
                No saved world yet: the first start makes a new one.
            </p>
            <ul v-else class="divide-y rounded-xl border" data-test="archives">
                <li
                    v-for="archive in props.world.archives"
                    :key="archive.id"
                    class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm"
                    :data-test="`archive-${archive.id}`"
                >
                    <div>
                        <p class="font-mono text-xs">
                            {{ archive.file_name }}
                            <span
                                v-if="archive.current"
                                class="ml-1 rounded bg-primary px-1.5 py-0.5 font-sans text-primary-foreground"
                                >current</span
                            >
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatSize(archive.size) }} ·
                            {{ describe(archive) }} ·
                            {{ formatDate(archive.created_at) }}
                        </p>
                    </div>
                    <Button variant="outline" size="sm" as-child>
                        <a
                            :href="
                                WorldArchiveController.show.url([
                                    props.server.slug,
                                    archive.id,
                                ])
                            "
                            :aria-label="`Download ${archive.file_name}`"
                            download
                            >Download</a
                        >
                    </Button>
                </li>
            </ul>

            <Form
                v-bind="WorldArchiveController.store.form(props.server.slug)"
                class="space-y-3"
                :options="{ preserveScroll: true }"
                reset-on-success
                v-slot="{ errors, processing, progress }"
            >
                <Label for="archive">Upload a world or modpack (.zip)</Label>
                <input
                    id="archive"
                    type="file"
                    name="archive"
                    accept=".zip,application/zip"
                    class="block w-full text-sm"
                    :disabled="props.world.running"
                />
                <p class="text-sm text-muted-foreground">
                    Up to {{ props.world.uploadMaxMb }} MB. It becomes the
                    current world: the server folder is unpacked from it on the
                    next start.
                    <template v-if="props.world.running"
                        >Stop the server first.</template
                    >
                </p>
                <progress
                    v-if="progress"
                    :value="progress.percentage"
                    max="100"
                    class="w-full"
                />
                <InputError :message="errors.archive" />
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="processing || props.world.running"
                    data-test="upload-world"
                    >Upload</Button
                >
            </Form>
        </section>
    </div>
</template>
