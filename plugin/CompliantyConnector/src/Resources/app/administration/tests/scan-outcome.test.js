import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { describeStartOutcome, formatWait } from '../src/module/complianty-connector/scan-outcome.js';

describe('formatWait', () => {
    const cases = [
        [0, null],
        [-5, null],
        [Number.NaN, null],
        [undefined, null],
        [1, { unit: 'seconds', count: 1 }],
        [59, { unit: 'seconds', count: 59 }],
        [60, { unit: 'minutes', count: 1 }],
        [61, { unit: 'minutes', count: 2 }],
        [120, { unit: 'minutes', count: 2 }],
        [3599, { unit: 'minutes', count: 60 }],
        [3600, { unit: 'hours', count: 1 }],
        [3601, { unit: 'hours', count: 2 }],
        [86400, { unit: 'hours', count: 24 }],
    ];

    for (const [seconds, expected] of cases) {
        it(`${seconds} Sekunden`, () => {
            assert.deepEqual(formatWait(seconds), expected);
        });
    }
});

describe('describeStartOutcome', () => {
    it('ok: gestartet, Polling mit ignorierten Idle-Antworten', () => {
        const outcome = describeStartOutcome({ state: 'ok', scan_state: 'queued' });

        assert.equal(outcome.started, true);
        assert.equal(outcome.polling, true);
        assert.equal(outcome.ignoreIdlePolls, 2);
        assert.equal(outcome.message.key, 'running');
        assert.equal(outcome.message.variant, 'info');
    });

    it('conflict: läuft schon, kein Fehler, weiter warten ohne Idle zu ignorieren', () => {
        const outcome = describeStartOutcome({ state: 'conflict', code: 'scan_already_running' });

        assert.equal(outcome.started, false);
        assert.equal(outcome.polling, true);
        assert.equal(outcome.ignoreIdlePolls, 0);
        assert.equal(outcome.message.key, 'alreadyRunning');
        assert.equal(outcome.message.variant, 'info');
    });

    it('cooldown: Warnung mit Wartezeit', () => {
        const outcome = describeStartOutcome({ state: 'cooldown', code: 'cooldown_active', retry_after: 600 });

        assert.equal(outcome.polling, false);
        assert.deepEqual(outcome.message, { variant: 'warning', key: 'cooldown', wait: { unit: 'minutes', count: 10 } });
    });

    it('cooldown ohne Zeitangabe: keine erfundene Wartezeit', () => {
        const outcome = describeStartOutcome({ state: 'cooldown', code: 'target_recently_scanned', retry_after: 0 });

        assert.equal(outcome.message.key, 'cooldown');
        assert.equal(outcome.message.wait, null);
    });

    const blocked = [
        [{ state: 'budget', code: 'daily_scan_budget_reached' }, 'budget', 'warning'],
        [{ state: 'forbidden', code: 'insufficient_scope' }, 'noScopeRight', 'error'],
        [{ state: 'forbidden', code: '' }, 'forbidden', 'error'],
        [{ state: 'rejected', code: 'unsafe_url' }, 'unsafeUrl', 'error'],
        [{ state: 'rejected', code: 'other' }, 'rejected', 'error'],
        [{ state: 'unreachable', code: 'scanner_busy' }, 'scannerBusy', 'warning'],
        [{ state: 'unreachable', code: '' }, 'unreachable', 'error'],
        [{ state: 'rate_limited' }, 'rateLimited', 'warning'],
        [{ state: 'unauthorized' }, 'unauthorized', 'error'],
        [{ state: 'subscription_inactive' }, 'subscriptionInactive', 'warning'],
        [{ state: 'feature_not_available' }, 'featureNotAvailable', 'warning'],
        [{ state: 'no_token' }, 'noToken', 'error'],
        [{ state: 'invalid' }, 'invalid', 'error'],
    ];

    for (const [payload, key, variant] of blocked) {
        it(`${payload.state} ${payload.code ?? ''} -> ${key}`, () => {
            const outcome = describeStartOutcome(payload);

            assert.equal(outcome.started, false);
            assert.equal(outcome.polling, false);
            assert.equal(outcome.message.key, key);
            assert.equal(outcome.message.variant, variant);
        });
    }

    it('unbekannte oder fehlende Zustände werden als ungültige Antwort behandelt, nie als Erfolg', () => {
        for (const payload of [{ state: 'surprise' }, {}, null, undefined]) {
            const outcome = describeStartOutcome(payload);

            assert.equal(outcome.started, false);
            assert.equal(outcome.polling, false);
            assert.equal(outcome.message.key, 'invalid');
        }
    });
});
