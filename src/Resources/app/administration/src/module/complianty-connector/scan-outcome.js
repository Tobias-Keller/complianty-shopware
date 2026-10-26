/**
 * Übersetzt die Antwort der Plugin-API auf "Scan starten" in das, was die Oberfläche zeigt.
 * Reine Funktionen ohne Shopware-Abhängigkeit.
 */

/**
 * Wartezeit in der größten sinnvollen Einheit. Aufgerundet: lieber eine Minute zu lang als zu kurz.
 *
 * @param {number} seconds
 * @returns {{ unit: 'seconds'|'minutes'|'hours', count: number }|null} null, wenn der Dienst keine Zeit nannte
 */
export function formatWait(seconds) {
    if (!Number.isFinite(seconds) || seconds <= 0) {
        return null;
    }

    if (seconds < 60) {
        return { unit: 'seconds', count: Math.ceil(seconds) };
    }

    if (seconds < 3600) {
        return { unit: 'minutes', count: Math.ceil(seconds / 60) };
    }

    return { unit: 'hours', count: Math.ceil(seconds / 3600) };
}

const BLOCKED_BY_STATE = {
    budget: 'budget',
    rate_limited: 'rateLimited',
    unreachable: 'unreachable',
    unauthorized: 'unauthorized',
    subscription_inactive: 'subscriptionInactive',
    feature_not_available: 'featureNotAvailable',
    no_token: 'noToken',
    invalid: 'invalid',
};

/**
 * @typedef {{
 *   started: boolean,
 *   polling: boolean,
 *   ignoreIdlePolls: number,
 *   message: { variant: 'info'|'warning'|'error', key: string, wait: { unit: string, count: number }|null }|null,
 * }} StartOutcome
 */

/**
 * @param {{ state?: string, code?: string, retry_after?: number }} payload Antwort von POST /scan
 * @returns {StartOutcome}
 */
export function describeStartOutcome(payload) {
    const state = payload?.state;
    const code = payload?.code;

    if (state === 'ok') {
        return {
            started: true,
            polling: true,
            ignoreIdlePolls: 2,
            message: { variant: 'info', key: 'running', wait: null },
        };
    }

    // Ein laufender Scan ist kein Fehler: weiter warten, aber nichts ignorieren, der Zustand steht schon fest.
    if (state === 'conflict') {
        return {
            started: false,
            polling: true,
            ignoreIdlePolls: 0,
            message: { variant: 'info', key: 'alreadyRunning', wait: null },
        };
    }

    let key = BLOCKED_BY_STATE[state] ?? 'invalid';
    let variant = 'error';
    let wait = null;

    if (state === 'cooldown') {
        key = 'cooldown';
        variant = 'warning';
        wait = formatWait(payload.retry_after);
    } else if (state === 'budget') {
        variant = 'warning';
    } else if (state === 'forbidden') {
        key = code === 'insufficient_scope' ? 'noScopeRight' : 'forbidden';
    } else if (state === 'rejected') {
        key = code === 'unsafe_url' ? 'unsafeUrl' : 'rejected';
    } else if (state === 'unreachable' && code === 'scanner_busy') {
        key = 'scannerBusy';
        variant = 'warning';
    } else if (['rate_limited', 'subscription_inactive', 'feature_not_available'].includes(state)) {
        variant = 'warning';
    }

    return { started: false, polling: false, ignoreIdlePolls: 0, message: { variant, key, wait } };
}
