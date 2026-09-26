import { onBeforeUnmount, onMounted, readonly, ref } from 'vue';

// R135: the badge counts come from one small JSON endpoint, polled every 60 seconds while the tab is
// visible. No broadcasting, no websocket: Reverb badges are on the CP8 hardening list.
const POLL_MS = 60_000;

const messages = ref(0);
const notifications = ref(0);

let subscribers = 0;
let timer: ReturnType<typeof setInterval> | null = null;
let inFlight = false;

async function refresh(): Promise<void> {
    if (inFlight || document.visibilityState !== 'visible') {
        return;
    }

    inFlight = true;

    try {
        const response = await fetch('/unread-counts', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (response.ok) {
            const data = (await response.json()) as { messages?: number; notifications?: number };
            messages.value = Number(data.messages ?? 0);
            notifications.value = Number(data.notifications ?? 0);
        }
    } catch {
        // A failed poll leaves the last counts; the next tick tries again.
    } finally {
        inFlight = false;
    }
}

function onVisibilityChange(): void {
    if (document.visibilityState === 'visible') {
        void refresh();
    }
}

/**
 * The counts are shared, so several components can read them and still cost one request per minute.
 * `enabled` is false when the feature is off: nothing is fetched then.
 */
export function useUnreadCounts(enabled: () => boolean = () => true) {
    onMounted(() => {
        if (!enabled()) {
            return;
        }

        subscribers++;

        if (subscribers === 1) {
            void refresh();
            timer = setInterval(() => void refresh(), POLL_MS);
            document.addEventListener('visibilitychange', onVisibilityChange);
        }
    });

    onBeforeUnmount(() => {
        if (subscribers === 0) {
            return;
        }

        subscribers--;

        if (subscribers === 0) {
            if (timer !== null) {
                clearInterval(timer);
                timer = null;
            }

            document.removeEventListener('visibilitychange', onVisibilityChange);
        }
    });

    return { messages: readonly(messages), notifications: readonly(notifications), refresh };
}
