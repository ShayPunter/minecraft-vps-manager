import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * Counts a server-sent number of seconds down in the browser (#5378). The server sends
 * the seconds left at the time of its answer, so the viewer's clock never matters; every
 * new answer (the pages poll) starts the count again from its own value.
 */
export function useCountdown(seconds: () => number | null): Ref<number | null> {
    const left = ref<number | null>(seconds());
    let receivedAt = Date.now();
    let received = seconds();
    let timer: ReturnType<typeof setInterval> | undefined;

    const tick = () => {
        left.value =
            received === null
                ? null
                : Math.max(
                      0,
                      received - Math.floor((Date.now() - receivedAt) / 1000),
                  );
    };

    watch(seconds, (value) => {
        received = value;
        receivedAt = Date.now();
        tick();
    });

    onMounted(() => {
        timer = setInterval(tick, 1000);
    });

    onBeforeUnmount(() => {
        if (timer !== undefined) {
            clearInterval(timer);
        }
    });

    return left;
}
