<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import ServerLogController from '@/actions/App/Http/Controllers/Admin/ServerLogController';
import Heading from '@/components/Heading.vue';
import { index } from '@/routes/admin/servers';

type LogLine = {
    id: number;
    source: 'setup' | 'prepare' | 'minecraft' | 'fallback';
    line: string;
    at: string | null;
};

type RunOption = {
    id: number;
    status: string;
    status_label: string;
    started_at: string | null;
    ended_at: string | null;
};

const props = defineProps<{
    server: { name: string; slug: string };
    runs: RunOption[];
    run: {
        id: number;
        status_label: string;
        active: boolean;
        error: string | null;
        started_at: string | null;
    } | null;
    lines: LogLine[];
    retentionDays: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Servers', href: index() },
            { title: 'Log', href: '#' },
        ],
    },
});

// Kept here, not in props: polling appends to it without a page visit.
const lines = ref<LogLine[]>([...props.lines]);
const statusLabel = ref(props.run?.status_label ?? '');
const error = ref(props.run?.error ?? null);
const active = ref(props.run?.active ?? false);
const follow = ref(true);
const box = ref<HTMLElement | null>(null);
let timer: ReturnType<typeof setInterval> | null = null;

const sourceLabels: Record<LogLine['source'], string> = {
    setup: 'setup',
    prepare: 'prepare',
    minecraft: 'server',
    fallback: 'pulled',
};

function when(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '';
}

async function scrollDown(): Promise<void> {
    await nextTick();

    if (follow.value && box.value) {
        box.value.scrollTop = box.value.scrollHeight;
    }
}

async function poll(): Promise<void> {
    if (!props.run) {
        return;
    }

    const after =
        lines.value.length > 0 ? lines.value[lines.value.length - 1].id : 0;
    const url = ServerLogController.lines.url(props.server.slug, {
        query: { run: props.run.id, after },
    });

    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        const data = (await response.json()) as {
            lines: LogLine[];
            active: boolean;
            status_label: string;
            error: string | null;
        };

        lines.value.push(...data.lines);
        statusLabel.value = data.status_label;
        error.value = data.error;
        active.value = data.active;

        if (!data.active && timer) {
            clearInterval(timer);
            timer = null;
        }

        if (data.lines.length > 0) {
            await scrollDown();
        }
    } catch {
        // Offline for a moment: the next tick tries again.
    }
}

function pickRun(event: Event): void {
    const id = (event.target as HTMLSelectElement).value;
    router.get(
        ServerLogController.show.url(props.server.slug, { query: { run: id } }),
    );
}

onMounted(() => {
    void scrollDown();

    if (active.value) {
        timer = setInterval(() => void poll(), 3000);
    }
});

onBeforeUnmount(() => {
    if (timer) {
        clearInterval(timer);
    }
});
</script>

<template>
    <Head :title="`Log: ${server.name}`" />

    <div class="flex flex-col gap-4 p-4">
        <Heading
            :title="`Log: ${server.name}`"
            :description="`What the VPS and Minecraft printed. Kept after the VPS is deleted, for ${retentionDays} days.`"
        />

        <p
            v-if="!run"
            class="text-sm text-muted-foreground"
            data-test="no-runs"
        >
            This server hasn't been started yet.
        </p>

        <template v-else>
            <div class="flex flex-wrap items-center gap-4 text-sm">
                <label class="flex items-center gap-2">
                    <span class="font-medium">Run</span>
                    <select
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm dark:bg-input/30"
                        data-test="run-picker"
                        @change="pickRun"
                    >
                        <option
                            v-for="option in runs"
                            :key="option.id"
                            :value="option.id"
                            :selected="option.id === run.id"
                        >
                            {{ when(option.started_at) }} ·
                            {{ option.status_label }}
                        </option>
                    </select>
                </label>
                <span data-test="run-status"
                    >{{ statusLabel
                    }}<template v-if="active"> · updating live</template></span
                >
                <label class="flex items-center gap-2">
                    <input v-model="follow" type="checkbox" class="size-4" />
                    Follow new lines
                </label>
            </div>

            <p
                v-if="error"
                class="rounded-md border border-destructive/40 p-3 text-sm text-destructive"
                data-test="run-error"
            >
                {{ error }}
            </p>

            <div
                ref="box"
                class="h-[60vh] overflow-auto rounded-xl border border-sidebar-border/70 bg-black p-3 font-mono text-xs leading-5 text-neutral-100"
                data-test="log-lines"
            >
                <p v-if="lines.length === 0" class="text-neutral-400">
                    No log lines yet.
                    <template v-if="active"
                        >They appear once the VPS has booted and the app has
                        logged in.</template
                    >
                </p>
                <div
                    v-for="line in lines"
                    :key="line.id"
                    class="break-all whitespace-pre-wrap"
                >
                    <span class="text-neutral-500 select-none"
                        >[{{ sourceLabels[line.source] }}]
                    </span>
                    {{ line.line }}
                </div>
            </div>

            <p class="text-sm text-muted-foreground">
                <Link :href="index()" class="underline">Back to servers</Link>
            </p>
        </template>
    </div>
</template>
