<script setup lang="ts">
import { reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ServerConfig } from '@/types/servers';

// server.properties, whitelist / ops and a pack's run.sh / user_jvm_args.txt (#5377).
// Everything starts from the saved world's own files; only what differs is saved, and the
// next start writes it over the world's files.
const props = defineProps<{
    errors: Record<string, string | undefined>;
    config: ServerConfig;
    running?: boolean;
}>();

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30';
const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30';

type Choice = { key: string; label: string; options: string[] };

const choices: Choice[] = [
    {
        key: 'difficulty',
        label: 'Difficulty',
        options: ['peaceful', 'easy', 'normal', 'hard'],
    },
    {
        key: 'gamemode',
        label: 'Game mode',
        options: ['survival', 'creative', 'adventure', 'spectator'],
    },
    { key: 'pvp', label: 'PvP', options: ['true', 'false'] },
    { key: 'white-list', label: 'Whitelist', options: ['true', 'false'] },
    {
        key: 'enforce-whitelist',
        label: 'Enforce whitelist',
        options: ['true', 'false'],
    },
    { key: 'allow-flight', label: 'Allow flight', options: ['true', 'false'] },
    { key: 'hardcore', label: 'Hardcore', options: ['true', 'false'] },
];

const numbers = [
    { key: 'max-players', label: 'Max players', min: 1, max: 1000 },
    { key: 'view-distance', label: 'View distance (chunks)', min: 2, max: 32 },
    {
        key: 'simulation-distance',
        label: 'Simulation distance (chunks)',
        min: 2,
        max: 32,
    },
    {
        key: 'spawn-protection',
        label: 'Spawn protection (blocks)',
        min: 0,
        max: 1000,
    },
];

const optionLabels: Record<string, string> = { true: 'On', false: 'Off' };

function defaultLabel(key: string): string {
    const world = props.config.world[key];

    return world
        ? `As the world has it (${optionLabels[world] ?? world})`
        : "Minecraft's default";
}

// Every field holds its own value (taken once from the props): a re-render, e.g. after a
// validation error or the "Use the pack's file" button, must not put the old text back.
const values = reactive<Record<string, string>>({ ...props.config.values });
const extra = ref(props.config.extra);
const whitelist = ref(props.config.whitelist);
const ops = ref(props.config.ops);
const runSh = ref(props.config.run_sh);
const userJvmArgs = ref(props.config.user_jvm_args);
</script>

<template>
    <div class="grid gap-4 border-t pt-6" data-test="server-config">
        <div>
            <h2 class="text-base font-medium">server.properties</h2>
            <p class="text-sm text-muted-foreground" data-test="config-source">
                <template v-if="config.has_world_files">
                    Filled from the saved world. Change what you need: the rest
                    keeps following the world.
                </template>
                <template v-else-if="config.has_world">
                    The saved world's settings show here after its next stop (or
                    upload it again). Fields you leave empty keep the world's
                    values.
                </template>
                <template v-else>
                    Fields you leave empty keep Minecraft's defaults.
                </template>
                Changes are written before every start<template v-if="running"
                    >, so they apply from the next start</template
                >.
            </p>
        </div>

        <div class="grid gap-2">
            <Label for="prop-motd">MOTD (shown in the server list)</Label>
            <Input
                id="prop-motd"
                name="props[motd]"
                v-model="values.motd"
                :placeholder="config.world.motd || 'A Minecraft Server'"
                autocomplete="off"
            />
            <InputError :message="errors['props.motd']" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div v-for="choice in choices" :key="choice.key" class="grid gap-2">
                <Label :for="`prop-${choice.key}`">{{ choice.label }}</Label>
                <select
                    :id="`prop-${choice.key}`"
                    :name="`props[${choice.key}]`"
                    v-model="values[choice.key]"
                    :class="selectClass"
                >
                    <option value="">
                        {{ defaultLabel(choice.key) }}
                    </option>
                    <option
                        v-for="option in choice.options"
                        :key="option"
                        :value="option"
                    >
                        {{ optionLabels[option] ?? option }}
                    </option>
                </select>
                <InputError :message="errors[`props.${choice.key}`]" />
            </div>

            <div v-for="field in numbers" :key="field.key" class="grid gap-2">
                <Label :for="`prop-${field.key}`">{{ field.label }}</Label>
                <Input
                    :id="`prop-${field.key}`"
                    :name="`props[${field.key}]`"
                    type="number"
                    :min="field.min"
                    :max="field.max"
                    v-model="values[field.key]"
                    :placeholder="config.world[field.key] || 'default'"
                />
                <InputError :message="errors[`props.${field.key}`]" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="extra_properties">Other settings</Label>
            <textarea
                id="extra_properties"
                name="extra_properties"
                rows="3"
                :class="textareaClass"
                placeholder="level-seed=12345"
                v-model="extra"
            />
            <p class="text-sm text-muted-foreground">
                One key=value per line. Settings not listed here stay as the
                world has them.
            </p>
            <details
                v-if="config.world_other.length > 0"
                class="text-sm text-muted-foreground"
            >
                <summary class="cursor-pointer">
                    The world's other settings ({{ config.world_other.length }})
                </summary>
                <pre
                    class="mt-2 max-h-48 overflow-auto rounded bg-muted p-2 font-mono text-xs"
                    data-test="world-other"
                    >{{ config.world_other.join('\n') }}</pre>
            </details>
            <InputError :message="errors.extra_properties" />
        </div>

        <h2 class="pt-2 text-base font-medium">Players</h2>

        <div class="grid gap-2">
            <Label for="whitelist_players">Whitelisted players</Label>
            <textarea
                id="whitelist_players"
                name="whitelist_players"
                rows="3"
                :class="textareaClass"
                placeholder="Steve"
                v-model="whitelist"
            />
            <p class="text-sm text-muted-foreground">
                One Minecraft account per line. Only these can join while the
                whitelist is on.
            </p>
            <InputError :message="errors.whitelist_players" />
        </div>

        <div class="grid gap-2">
            <Label for="ops_players">Operators</Label>
            <textarea
                id="ops_players"
                name="ops_players"
                rows="2"
                :class="textareaClass"
                placeholder="Steve 4"
                v-model="ops"
            />
            <p class="text-sm text-muted-foreground">
                One account per line, optionally followed by a level from 1 to 4
                (4 if left out).
            </p>
            <InputError :message="errors.ops_players" />
        </div>

        <h2 class="pt-2 text-base font-medium">Files</h2>

        <div class="grid gap-2">
            <div class="flex items-center justify-between gap-2">
                <Label for="run_sh">run.sh</Label>
                <button
                    v-if="config.run_sh_pack !== null"
                    type="button"
                    class="text-sm underline"
                    data-test="reset-run-sh"
                    @click="runSh = config.run_sh_pack ?? ''"
                >
                    Use the pack's file
                </button>
            </div>
            <textarea
                id="run_sh"
                v-model="runSh"
                name="run_sh"
                rows="6"
                :class="textareaClass"
            />
            <p class="text-sm text-muted-foreground" data-test="run-sh-status">
                <template v-if="runSh !== (config.run_sh_pack ?? '')"
                    >Your version is written over the pack's run.sh before every
                    start.</template
                >
                <template v-else-if="config.run_sh_pack !== null"
                    >The pack's own run.sh: nothing is written.</template
                >
                <template v-else
                    >The world has no run.sh. Anything typed here is written as
                    run.sh.</template
                >
            </p>
            <InputError :message="errors.run_sh" />
        </div>

        <div class="grid gap-2">
            <div class="flex items-center justify-between gap-2">
                <Label for="user_jvm_args">user_jvm_args.txt</Label>
                <button
                    v-if="config.user_jvm_args_pack !== null"
                    type="button"
                    class="text-sm underline"
                    data-test="reset-user-jvm-args"
                    @click="userJvmArgs = config.user_jvm_args_pack ?? ''"
                >
                    Use the pack's file
                </button>
            </div>
            <textarea
                id="user_jvm_args"
                v-model="userJvmArgs"
                name="user_jvm_args"
                rows="4"
                :class="textareaClass"
            />
            <p
                class="text-sm text-muted-foreground"
                data-test="user-jvm-args-status"
            >
                <template
                    v-if="userJvmArgs !== (config.user_jvm_args_pack ?? '')"
                    >Your version is written over the pack's file before every
                    start.</template
                >
                <template v-else-if="config.user_jvm_args_pack !== null"
                    >The pack's own file: nothing is written.</template
                >
                <template v-else
                    >Forge and NeoForge packs keep their memory flags (-Xmx)
                    here.</template
                >
            </p>
            <InputError :message="errors.user_jvm_args" />
        </div>
    </div>
</template>
