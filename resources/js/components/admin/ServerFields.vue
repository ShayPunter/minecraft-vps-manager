<script setup lang="ts">
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    ServerCatalog,
    ServerDefinition,
    ServerTypeOption,
    SoftwareOptions,
    SoftwareValue,
    Visibility,
} from '@/types/servers';

const props = defineProps<{
    errors: Record<string, string | undefined>;
    catalog: ServerCatalog;
    javaVersions: number[];
    software: SoftwareOptions;
    dnsDomain?: string | null;
    server?: ServerDefinition;
    hasSavedWorld?: boolean;
}>();

// What it runs (#5351). New servers default to Vanilla at the newest release, unless the
// version lists couldn't be loaded; existing servers keep what they have.
const chosen = ref<SoftwareValue>(
    props.server?.software ??
        ((props.software.versions.vanilla ?? []).length > 0
            ? 'vanilla'
            : 'custom'),
);
const versionsHere = computed(
    () => props.software.versions[chosen.value] ?? [],
);
const minecraftVersion = ref(
    props.server?.minecraft_version ?? versionsHere.value[0] ?? '',
);
const installs = computed(() => chosen.value !== 'custom');

function chooseSoftware(value: SoftwareValue): void {
    chosen.value = value;

    if (!versionsHere.value.includes(minecraftVersion.value)) {
        minecraftVersion.value = versionsHere.value[0] ?? '';
    }
}

// The saved server's own command is only shown while the choice is still what it saved.
const savedInstallCommand = computed(() =>
    props.server &&
    props.server.software === chosen.value &&
    props.server.minecraft_version === minecraftVersion.value
        ? props.server.start_command
        : null,
);

const subdomain = ref(props.server?.subdomain ?? '');

// Who sees it (#5462). Same wording as App\Enums\Visibility.
const visibility = ref<Visibility>(props.server?.visibility ?? 'private');
const visibilityOptions: {
    value: Visibility;
    label: string;
    description: string;
}[] = [
    {
        value: 'public',
        label: 'Public',
        description: 'Listed on the home page. Anyone can start it.',
    },
    {
        value: 'private',
        label: 'Private',
        description: 'Signed-in players see it on their dashboard.',
    },
    {
        value: 'link',
        label: 'Link only',
        description:
            'Only people with its share link see it, and they need no account. The link is shown here once you save.',
    },
];

// In the script, not a template attribute: vue-tsc 3.3.13 misreads "https://" there.
const eulaUrl = 'https://aka.ms/MinecraftEULA';

const eulaAcceptedOn = computed(() =>
    props.server?.eula_accepted_at
        ? new Date(props.server.eula_accepted_at).toLocaleDateString()
        : null,
);

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

// Only types that can be ordered in the chosen location, grouped by the provider's
// category, cheapest first; groups ordered by their cheapest type (#5321).
const groupsHere = computed(() => {
    const here = props.catalog.types
        .filter((type) => location.value in type.prices)
        .sort(
            (a, b) =>
                a.prices[location.value].sort - b.prices[location.value].sort,
        );
    const groups = new Map<string, ServerTypeOption[]>();

    for (const type of here) {
        groups.set(type.group, [...(groups.get(type.group) ?? []), type]);
    }

    return [...groups].map(([name, types]) => ({ name, types }));
});

// An existing server whose type has since been retired here keeps it until edited.
const currentTypeRetired = computed(
    () =>
        props.server !== undefined &&
        serverType !== '' &&
        props.catalog.types.length > 0 &&
        !groupsHere.value.some((group) =>
            group.types.some((type) => type.name === serverType),
        ),
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

    <div class="grid gap-2">
        <Label for="visibility">Visibility</Label>
        <select
            id="visibility"
            name="visibility"
            :class="selectClass"
            @change="
                visibility = ($event.target as HTMLSelectElement)
                    .value as Visibility
            "
        >
            <option
                v-for="option in visibilityOptions"
                :key="option.value"
                :value="option.value"
                :selected="option.value === visibility"
            >
                {{ option.label }}
            </option>
        </select>
        <p class="text-sm text-muted-foreground" data-test="visibility-help">
            {{
                visibilityOptions.find((o) => o.value === visibility)
                    ?.description
            }}
        </p>
        <InputError :message="errors.visibility" />
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
            <option v-if="currentTypeRetired" :value="serverType" selected>
                {{ serverType }} (can't be ordered here)
            </option>
            <optgroup
                v-for="group in groupsHere"
                :key="group.name"
                :label="group.name"
            >
                <option
                    v-for="option in group.types"
                    :key="option.name"
                    :value="option.name"
                    :selected="option.name === serverType"
                >
                    {{ option.label }} · {{ option.prices[location].hourly }} ·
                    {{ option.prices[location].monthly }}
                </option>
            </optgroup>
        </select>
        <Input
            v-else
            id="server_type"
            name="server_type"
            :default-value="serverType"
            required
        />
        <p
            v-if="currentTypeRetired"
            class="text-sm text-destructive"
            data-test="type-retired"
        >
            {{ serverType }} can't be ordered in this location any more. Pick
            another type before saving.
        </p>
        <p class="text-sm text-muted-foreground">
            Billed by the hour, only while the server runs.
        </p>
        <InputError :message="errors.server_type" />
    </div>

    <div class="grid gap-2">
        <Label for="software">Software</Label>
        <select
            id="software"
            name="software"
            :class="selectClass"
            @change="
                chooseSoftware(
                    ($event.target as HTMLSelectElement).value as SoftwareValue,
                )
            "
        >
            <option
                v-for="option in props.software.options"
                :key="option.value"
                :value="option.value"
                :selected="option.value === chosen"
            >
                {{ option.label }}
            </option>
        </select>
        <p
            v-if="props.software.error"
            class="text-sm text-destructive"
            data-test="software-error"
        >
            {{ props.software.error }} Vanilla, Paper and Fabric need it; your
            own start command still works.
        </p>
        <InputError :message="errors.software" />
    </div>

    <div v-if="installs" class="grid gap-2">
        <Label for="minecraft_version">Minecraft version</Label>
        <select
            v-if="versionsHere.length > 0"
            id="minecraft_version"
            name="minecraft_version"
            :class="selectClass"
            @change="
                minecraftVersion = ($event.target as HTMLSelectElement).value
            "
        >
            <option
                v-for="(version, i) in versionsHere"
                :key="version"
                :value="version"
                :selected="version === minecraftVersion"
            >
                {{ version }}{{ i === 0 ? ' (latest)' : '' }}
            </option>
        </select>
        <Input
            v-else
            id="minecraft_version"
            v-model="minecraftVersion"
            name="minecraft_version"
            required
            autocomplete="off"
        />
        <p class="text-sm text-muted-foreground" data-test="install-hint">
            <template v-if="hasSavedWorld">
                This server has a saved world, so starts use that. Changing the
                version here doesn't reinstall it.
            </template>
            <template v-else>
                The first start installs it (the jar is checked against the hash
                its source publishes) and the first stop saves the whole folder,
                so later starts don't reinstall.
            </template>
            Java and the memory flags follow the version and the server
            type<template v-if="savedInstallCommand"
                >:
                <span class="font-mono" data-test="install-command">{{
                    savedInstallCommand
                }}</span></template
            >.
        </p>
        <InputError :message="errors.minecraft_version" />
    </div>

    <div v-if="!installs" class="grid gap-2">
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

    <div v-if="!installs" class="grid gap-2">
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

    <div class="grid gap-2">
        <div class="flex items-center gap-2">
            <input
                id="eula"
                type="checkbox"
                name="eula"
                value="1"
                :checked="!!server?.eula_accepted_at"
                class="size-4"
            />
            <Label for="eula"
                >I accept the Minecraft EULA for this server</Label
            >
        </div>
        <p class="text-sm text-muted-foreground" data-test="eula-hint">
            Minecraft won't start until its EULA is accepted. Ticked, every
            start writes eula.txt with eula=true. Read it at
            <a
                :href="eulaUrl"
                target="_blank"
                rel="noopener"
                class="underline"
                >{{ eulaUrl }}</a
            >.
            <template v-if="server?.eula_accepted_at"
                >Accepted by
                {{ server.eula_accepted_by ?? 'a deleted user' }} on
                {{ eulaAcceptedOn }}.</template
            >
        </p>
        <InputError :message="errors.eula" />
    </div>

    <div class="grid gap-2">
        <Label for="idle_minutes">Stop after (minutes with nobody on)</Label>
        <Input
            id="idle_minutes"
            name="idle_minutes"
            type="number"
            min="5"
            max="240"
            :default-value="server?.idle_minutes ?? 15"
            required
        />
        <p class="text-sm text-muted-foreground">
            The world is saved, then the VPS is deleted. Time before Minecraft
            is up doesn't count.
        </p>
        <InputError :message="errors.idle_minutes" />
    </div>

    <div class="grid gap-2">
        <Label for="subdomain">Subdomain (optional)</Label>
        <div class="flex items-center gap-2">
            <Input
                id="subdomain"
                v-model="subdomain"
                name="subdomain"
                placeholder="atm9"
                autocomplete="off"
            />
            <span
                v-if="dnsDomain"
                class="text-sm whitespace-nowrap text-muted-foreground"
                >.{{ dnsDomain }}</span
            >
        </div>
        <p class="text-sm text-muted-foreground" data-test="subdomain-hint">
            <template v-if="dnsDomain && subdomain">
                Players join with
                <span class="font-mono"
                    >{{ subdomain.trim().toLowerCase() }}.{{ dnsDomain }}</span
                >
                while it runs.
            </template>
            <template v-else-if="dnsDomain">
                A name that points at the VPS while the server runs.
            </template>
            <template v-else>
                Set up Admin → DNS to give servers a name instead of an IP.
            </template>
        </p>
        <InputError :message="errors.subdomain" />
    </div>
</template>
