<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ModrinthController from '@/actions/App/Http/Controllers/Admin/ModrinthController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

// The Modrinth modpack picker in the server form (#5352): search by name, pick a pack, then
// one of its versions. Only the two ids are submitted; the app looks up the rest itself.

type Hit = {
    id: string;
    slug: string;
    title: string;
    description: string;
    icon_url: string | null;
    downloads: number;
};

type Version = {
    id: string;
    name: string;
    version_number: string;
    minecraft_version: string;
    loader: string;
};

const props = defineProps<{
    projectId?: string | null;
    versionId?: string | null;
    title?: string | null;
    errors: Record<string, string | undefined>;
    selectClass: string;
}>();

const query = ref('');
const hits = ref<Hit[] | null>(null);
const searching = ref(false);
const problem = ref<string | null>(null);

const project = ref<{ id: string; title: string } | null>(
    props.projectId
        ? { id: props.projectId, title: props.title ?? props.projectId }
        : null,
);
const versions = ref<Version[]>([]);
const versionId = ref(props.versionId ?? '');
const loadingVersions = ref(false);

const loaderNames: Record<string, string> = {
    fabric: 'Fabric',
    quilt: 'Quilt',
    neoforge: 'NeoForge',
    forge: 'Forge',
};

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    const data = (await response.json()) as T & { message?: string };

    if (!response.ok) {
        throw new Error(
            data.message ?? `Modrinth answered ${response.status}.`,
        );
    }

    return data;
}

async function search(): Promise<void> {
    searching.value = true;
    problem.value = null;

    try {
        const data = await getJson<{ hits: Hit[] }>(
            ModrinthController.search.url({ query: { q: query.value } }),
        );
        hits.value = data.hits;
    } catch (e) {
        problem.value = (e as Error).message;
        hits.value = null;
    } finally {
        searching.value = false;
    }
}

async function loadVersions(id: string): Promise<void> {
    loadingVersions.value = true;
    problem.value = null;

    try {
        const data = await getJson<{ versions: Version[] }>(
            ModrinthController.versions.url(id),
        );
        versions.value = data.versions;

        if (!versions.value.some((v) => v.id === versionId.value)) {
            versionId.value = versions.value[0]?.id ?? '';
        }
    } catch (e) {
        problem.value = (e as Error).message;
        versions.value = [];
    } finally {
        loadingVersions.value = false;
    }
}

function pick(hit: Hit): void {
    project.value = { id: hit.id, title: hit.title };
    hits.value = null;
    versionId.value = '';
    void loadVersions(hit.id);
}

onMounted(() => {
    if (project.value) {
        void loadVersions(project.value.id);
    }
});
</script>

<template>
    <div class="grid gap-2">
        <Label for="modrinth_query">Modrinth modpack</Label>
        <div class="flex gap-2">
            <Input
                id="modrinth_query"
                v-model="query"
                placeholder="Search Modrinth, e.g. Fabulously"
                autocomplete="off"
                @keydown.enter.prevent="search"
            />
            <Button
                type="button"
                variant="secondary"
                :disabled="searching"
                data-test="modrinth-search"
                @click="search"
            >
                Search
            </Button>
        </div>
        <p
            v-if="problem"
            class="text-sm text-destructive"
            data-test="modrinth-problem"
        >
            {{ problem }}
        </p>
        <ul
            v-if="hits"
            class="divide-y rounded-md border"
            data-test="modrinth-hits"
        >
            <li
                v-if="hits.length === 0"
                class="p-3 text-sm text-muted-foreground"
            >
                No server modpacks match.
            </li>
            <li v-for="hit in hits" :key="hit.id">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 p-3 text-left hover:bg-muted"
                    :aria-label="`Pick ${hit.title}`"
                    @click="pick(hit)"
                >
                    <img
                        v-if="hit.icon_url"
                        :src="hit.icon_url"
                        alt=""
                        class="size-8 rounded"
                    />
                    <span class="grid">
                        <span class="font-medium">{{ hit.title }}</span>
                        <span class="text-sm text-muted-foreground">{{
                            hit.description
                        }}</span>
                    </span>
                </button>
            </li>
        </ul>
        <input
            type="hidden"
            name="modrinth_project_id"
            :value="project?.id ?? ''"
        />
        <p v-if="project" class="text-sm" data-test="modrinth-picked">
            Picked: <span class="font-medium">{{ project.title }}</span>
        </p>
        <InputError :message="errors.modrinth_project_id" />
    </div>

    <div v-if="project" class="grid gap-2">
        <Label for="modrinth_version_id">Modpack version</Label>
        <select
            v-if="versions.length > 0"
            id="modrinth_version_id"
            name="modrinth_version_id"
            :class="selectClass"
            @change="versionId = ($event.target as HTMLSelectElement).value"
        >
            <option
                v-for="version in versions"
                :key="version.id"
                :value="version.id"
                :selected="version.id === versionId"
            >
                {{ version.version_number }} · Minecraft
                {{ version.minecraft_version }} ·
                {{ loaderNames[version.loader] ?? version.loader }}
            </option>
        </select>
        <template v-else>
            <input
                type="hidden"
                name="modrinth_version_id"
                :value="versionId"
            />
            <p class="text-sm text-muted-foreground">
                {{
                    loadingVersions
                        ? 'Loading its versions…'
                        : 'No version of this pack can be installed here (it needs a .mrpack for Fabric, Quilt, NeoForge or Forge).'
                }}
            </p>
        </template>
        <InputError :message="errors.modrinth_version_id" />
    </div>
</template>
