<script setup lang="ts">
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ServerCatalog, ServerDefinition } from '@/types/servers';

const props = defineProps<{
    errors: Record<string, string | undefined>;
    catalog: ServerCatalog;
    javaVersions: number[];
    server?: ServerDefinition;
}>();

// In the script, not the template: vue-tsc 3.3.13 misreports templates with https:// attributes.
const iconPlaceholder = 'https://…';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30';

const location = ref(
    props.server?.location ??
        props.catalog.defaults.location ??
        props.catalog.locations[0]?.name ??
        '',
);
const serverType =
    props.server?.server_type ?? props.catalog.defaults.server_type ?? '';

// Only types sold in the chosen location, with that location's price.
const typesHere = computed(() =>
    props.catalog.types.filter((type) => location.value in type.prices),
);
</script>

<template>
    <div class="grid gap-2">
        <Label for="name">Name</Label>
        <Input
            id="name"
            name="name"
            :default-value="server?.name ?? ''"
            required
            autocomplete="off"
        />
        <InputError :message="errors.name" />
    </div>

    <div class="grid gap-2">
        <Label for="slug">Slug</Label>
        <Input
            id="slug"
            name="slug"
            :default-value="server?.slug ?? ''"
            required
            autocomplete="off"
            placeholder="all-the-mods-9"
        />
        <p class="text-sm text-muted-foreground">
            Lowercase letters, digits and dashes. Names the VPS and its service.
        </p>
        <InputError :message="errors.slug" />
    </div>

    <div class="grid gap-2">
        <Label for="description">Description</Label>
        <Input
            id="description"
            name="description"
            :default-value="server?.description ?? ''"
            autocomplete="off"
        />
        <InputError :message="errors.description" />
    </div>

    <div class="flex items-center gap-2">
        <input
            id="is_public"
            type="checkbox"
            name="is_public"
            value="1"
            :checked="server?.is_public ?? false"
            class="size-4"
        />
        <Label for="is_public"
            >Public: listed on the home page, anyone can start it</Label
        >
    </div>

    <div class="grid gap-2">
        <Label for="icon_url">Icon URL</Label>
        <Input
            id="icon_url"
            name="icon_url"
            type="url"
            :default-value="server?.icon_url ?? ''"
            :placeholder="iconPlaceholder"
            autocomplete="off"
        />
        <InputError :message="errors.icon_url" />
    </div>

    <p
        v-if="catalog.error"
        class="text-sm text-destructive"
        data-test="catalog-error"
    >
        {{ catalog.error }} Type the Hetzner names by hand.
    </p>

    <div class="grid gap-2">
        <Label for="location">Location</Label>
        <!-- :selected on the options, not :value on the select: Vue sets the value before the options exist. -->
        <select
            v-if="catalog.locations.length > 0"
            id="location"
            name="location"
            :class="selectClass"
            @change="location = ($event.target as HTMLSelectElement).value"
        >
            <option
                v-for="option in catalog.locations"
                :key="option.name"
                :value="option.name"
                :selected="option.name === location"
            >
                {{ option.label }}
            </option>
        </select>
        <Input
            v-else
            id="location"
            name="location"
            :default-value="location"
            required
        />
        <InputError :message="errors.location" />
    </div>

    <div class="grid gap-2">
        <Label for="server_type">Server type</Label>
        <select
            v-if="catalog.types.length > 0"
            id="server_type"
            name="server_type"
            :class="selectClass"
        >
            <option
                v-for="option in typesHere"
                :key="option.name"
                :value="option.name"
                :selected="option.name === serverType"
            >
                {{ option.label }} · {{ option.prices[location] }}
            </option>
        </select>
        <Input
            v-else
            id="server_type"
            name="server_type"
            :default-value="serverType"
            required
        />
        <p class="text-sm text-muted-foreground">
            Billed by the hour, only while the server runs.
        </p>
        <InputError :message="errors.server_type" />
    </div>

    <div class="grid gap-2">
        <Label for="start_command">Start command</Label>
        <Input
            id="start_command"
            name="start_command"
            :default-value="server?.start_command ?? './run.sh'"
            required
            autocomplete="off"
            class="font-mono"
        />
        <p class="text-sm text-muted-foreground">
            Runs in the server's folder on the VPS.
        </p>
        <InputError :message="errors.start_command" />
    </div>

    <div class="grid gap-2">
        <Label for="java_version">Java version</Label>
        <select id="java_version" name="java_version" :class="selectClass">
            <option
                v-for="version in javaVersions"
                :key="version"
                :value="version"
                :selected="version === (server?.java_version ?? 21)"
            >
                Java {{ version }}
            </option>
        </select>
        <InputError :message="errors.java_version" />
    </div>
</template>
