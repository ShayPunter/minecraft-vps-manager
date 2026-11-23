import { router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, watch } from 'vue';
import type { ServerStatus } from '@/types/servers';

/** While something is moving: provisioning, booting, starting or stopping. */
export const FAST_POLL_MS = 5_000;

/**
 * When every server is stopped, online or failed (#5753). The idle countdowns tick in the
 * browser (useCountdown), so a slow poll only delays news from other tabs: someone else's
 * Start, the watchdog's stop, the player count (which the watchdog reads once a minute).
 */
export const IDLE_POLL_MS = 30_000;

const MOVING: readonly ServerStatus[] = [
    'provisioning',
    'booting',
    'starting',
    'stopping',
];

export function pollInterval(statuses: readonly ServerStatus[]): number {
    return statuses.some((status) => MOVING.includes(status))
        ? FAST_POLL_MS
        : IDLE_POLL_MS;
}

/**
 * Reloads `only` every 5 s while a server is changing state and every 30 s otherwise.
 * The poll restarts at the new interval as soon as the statuses change (a Start in this
 * tab switches to 5 s straight away).
 */
export function useStatusPoll(
    statuses: () => readonly ServerStatus[],
    only: string[],
): void {
    const interval = computed(() => pollInterval(statuses()));
    let poll: ReturnType<typeof router.poll> | null = null;

    const begin = (ms: number): void => {
        poll?.destroy();
        poll = router.poll(ms, { only });
    };

    onMounted(() => begin(interval.value));
    watch(interval, (ms) => begin(ms));
    onUnmounted(() => {
        poll?.destroy();
        poll = null;
    });
}
