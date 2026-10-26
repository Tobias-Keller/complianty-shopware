/**
 * Fragt den Scan-Zustand ab, bis der Scan fertig ist.
 *
 * Reine Zustandsmaschine ohne Shopware- und DOM-Abhängigkeit, damit sie sich mit Fake-Zeitgeber testen lässt.
 * Das Verhalten entspricht dem WordPress-Plugin: Direkt nach dem Start meldet der Dienst oft noch "idle",
 * weil der Worker den Zustand erst kurz nach der Annahme setzt. Die ersten Idle-Antworten zählen deshalb nicht
 * als "fertig", sonst bricht die Anzeige ab, bevor der Scan überhaupt begonnen hat. Sobald einmal queued oder
 * running zu sehen war, ist ein folgendes idle sicher das Ende und wird sofort übernommen.
 */
export const POLL_INTERVAL_MS = 5000;
export const INITIAL_DELAY_MS = 2000;
export const MAX_POLLS = 180;
export const IGNORED_IDLE_POLLS_AFTER_START = 2;

/**
 * @typedef {{ ok: boolean, scanState: string|null, lastScanError?: string|null }} PollReading
 */

/**
 * @param {{
 *   read: () => Promise<PollReading>,
 *   onDone: (reading: PollReading) => void,
 *   onError: (reading: PollReading|null) => void,
 *   onTimeout: () => void,
 *   schedule?: (callback: () => void, delay: number) => unknown,
 *   cancel?: (handle: unknown) => void,
 *   intervalMs?: number,
 *   initialDelayMs?: number,
 *   maxPolls?: number,
 * }} options
 */
export function createScanPoller(options) {
    const {
        read,
        onDone,
        onError,
        onTimeout,
        schedule = (callback, delay) => setTimeout(callback, delay),
        cancel = (handle) => clearTimeout(handle),
        intervalMs = POLL_INTERVAL_MS,
        initialDelayMs = INITIAL_DELAY_MS,
        maxPolls = MAX_POLLS,
    } = options;

    let handle = null;
    let running = false;
    // Jeder Start bekommt eine Generation: Antworten eines abgebrochenen Laufs werden verworfen.
    let generation = 0;

    function tick(runId, state) {
        handle = null;
        state.count += 1;

        read().then((reading) => {
            if (!running || runId !== generation) {
                return;
            }

            if (!reading || !reading.ok) {
                running = false;
                onError(reading || null);
                return;
            }

            if (reading.scanState !== 'idle') {
                state.sawActive = true;
            }

            if (reading.scanState === 'idle' && (state.sawActive || state.count > state.ignoreIdlePolls)) {
                running = false;
                onDone(reading);
                return;
            }

            if (state.count >= maxPolls) {
                running = false;
                onTimeout();
                return;
            }

            handle = schedule(() => tick(runId, state), intervalMs);
        }).catch(() => {
            if (running && runId === generation) {
                running = false;
                onError(null);
            }
        });
    }

    return {
        /**
         * @param {{ ignoreIdlePolls?: number }} [startOptions] 0 beim Fortsetzen eines laufenden Scans,
         *   IGNORED_IDLE_POLLS_AFTER_START direkt nach dem Auslösen.
         */
        start(startOptions = {}) {
            this.stop();
            running = true;
            generation += 1;
            const runId = generation;
            const state = { count: 0, sawActive: false, ignoreIdlePolls: startOptions.ignoreIdlePolls ?? 0 };
            handle = schedule(() => tick(runId, state), initialDelayMs);
        },

        stop() {
            running = false;
            generation += 1;
            if (handle !== null) {
                cancel(handle);
                handle = null;
            }
        },

        isRunning() {
            return running;
        },
    };
}
