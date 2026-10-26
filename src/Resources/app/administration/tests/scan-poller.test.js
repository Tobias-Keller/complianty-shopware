import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { IGNORED_IDLE_POLLS_AFTER_START, MAX_POLLS, createScanPoller } from '../src/module/complianty-connector/scan-poller.js';

/** Zeitgeber, der Aufrufe sammelt und von Hand abgearbeitet wird. */
function fakeClock() {
    const queue = [];
    return {
        schedule(callback, delay) {
            const entry = { callback, delay, cancelled: false };
            queue.push(entry);
            return entry;
        },
        cancel(entry) {
            entry.cancelled = true;
        },
        pending() {
            return queue.filter((entry) => !entry.cancelled && !entry.fired);
        },
        async fire() {
            const entry = this.pending()[0];
            assert.ok(entry, 'Es ist kein Aufruf eingeplant.');
            entry.fired = true;
            entry.callback();
            // Die Promise-Ketten des Pollers auslaufen lassen.
            await new Promise((resolve) => setImmediate(resolve));
            return entry.delay;
        },
    };
}

function setup(readings, extra = {}) {
    const clock = fakeClock();
    const events = [];
    let index = 0;
    const poller = createScanPoller({
        read: () => {
            const next = typeof readings === 'function' ? readings(index) : readings[Math.min(index, readings.length - 1)];
            index += 1;
            return next instanceof Error ? Promise.reject(next) : Promise.resolve(next);
        },
        onDone: (reading) => events.push(['done', reading.scanState]),
        onError: () => events.push(['error']),
        onTimeout: () => events.push(['timeout']),
        schedule: clock.schedule,
        cancel: clock.cancel,
        ...extra,
    });

    return { poller, clock, events, reads: () => index };
}

const idle = { ok: true, scanState: 'idle' };
const running = { ok: true, scanState: 'running' };
const queued = { ok: true, scanState: 'queued' };

describe('createScanPoller', () => {
    it('wartet zuerst kurz, dann im festen Takt', async () => {
        const { poller, clock } = setup([running, running]);

        poller.start();
        assert.equal(await clock.fire(), 2000);
        assert.equal(await clock.fire(), 5000);
    });

    it('meldet fertig, sobald ein fortgesetzter Scan idle ist', async () => {
        const { poller, clock, events } = setup([running, idle]);

        poller.start({ ignoreIdlePolls: 0 });
        await clock.fire();
        assert.deepEqual(events, []);
        await clock.fire();

        assert.deepEqual(events, [['done', 'idle']]);
        assert.equal(poller.isRunning(), false);
        assert.equal(clock.pending().length, 0);
    });

    it('ignoriert die ersten Idle-Antworten direkt nach dem Start', async () => {
        const { poller, clock, events } = setup([idle, idle, idle, idle]);

        poller.start({ ignoreIdlePolls: IGNORED_IDLE_POLLS_AFTER_START });
        await clock.fire();
        await clock.fire();
        assert.deepEqual(events, [], 'Zwei Idle-Antworten reichen nicht.');
        await clock.fire();

        assert.deepEqual(events, [['done', 'idle']]);
    });

    it('bleibt nach einem frühen Idle bei queued/running dran', async () => {
        const { poller, clock, events } = setup([idle, queued, running, running, idle]);

        poller.start({ ignoreIdlePolls: 2 });
        for (let i = 0; i < 4; i += 1) {
            await clock.fire();
        }
        assert.deepEqual(events, [], 'Das frühe Idle hat den Lauf nicht beendet.');
        await clock.fire();

        assert.deepEqual(events, [['done', 'idle']]);
    });

    it('übernimmt ein Idle sofort, wenn der Scan vorher aktiv zu sehen war', async () => {
        const { poller, clock, events, reads } = setup([queued, idle]);

        poller.start({ ignoreIdlePolls: 2 });
        await clock.fire();
        await clock.fire();

        assert.deepEqual(events, [['done', 'idle']]);
        assert.equal(reads(), 2, 'Ein sehr kurzer Scan wird nicht künstlich verlängert.');
    });

    it('übergibt die letzte Antwort an onDone', async () => {
        const done = [];
        const clock = fakeClock();
        const poller = createScanPoller({
            read: () => Promise.resolve({ ok: true, scanState: 'idle', lastScanError: 'Timeout' }),
            onDone: (reading) => done.push(reading),
            onError: () => {},
            onTimeout: () => {},
            schedule: clock.schedule,
            cancel: clock.cancel,
        });

        poller.start();
        await clock.fire();

        assert.deepEqual(done, [{ ok: true, scanState: 'idle', lastScanError: 'Timeout' }]);
    });

    it('bricht bei einer fehlgeschlagenen Abfrage mit onError ab', async () => {
        const { poller, clock, events } = setup([running, { ok: false, scanState: null }]);

        poller.start();
        await clock.fire();
        await clock.fire();

        assert.deepEqual(events, [['error']]);
        assert.equal(poller.isRunning(), false);
        assert.equal(clock.pending().length, 0);
    });

    it('behandelt eine abgelehnte Abfrage (Netzfehler) wie einen Fehler', async () => {
        const { poller, clock, events } = setup([new Error('offline')]);

        poller.start();
        await clock.fire();

        assert.deepEqual(events, [['error']]);
    });

    it('gibt nach der Höchstzahl an Abfragen mit onTimeout auf', async () => {
        const { poller, clock, events, reads } = setup([running]);

        poller.start();
        for (let i = 0; i < MAX_POLLS; i += 1) {
            await clock.fire();
        }

        assert.deepEqual(events, [['timeout']]);
        assert.equal(reads(), MAX_POLLS);
        assert.equal(clock.pending().length, 0);
    });

    it('stoppt sauber und verwirft eine noch ausstehende Antwort', async () => {
        const clock = fakeClock();
        const events = [];
        let release;
        const poller = createScanPoller({
            read: () => new Promise((resolve) => {
                release = resolve;
            }),
            onDone: () => events.push('done'),
            onError: () => events.push('error'),
            onTimeout: () => events.push('timeout'),
            schedule: clock.schedule,
            cancel: clock.cancel,
        });

        poller.start();
        clock.pending()[0].callback();
        poller.stop();
        release({ ok: true, scanState: 'idle' });
        await new Promise((resolve) => setImmediate(resolve));

        assert.deepEqual(events, [], 'Nach stop() darf keine Meldung mehr kommen.');
        assert.equal(poller.isRunning(), false);
    });

    it('stop() nimmt den eingeplanten Aufruf zurück', () => {
        const { poller, clock } = setup([running]);

        poller.start();
        assert.equal(clock.pending().length, 1);
        poller.stop();

        assert.equal(clock.pending().length, 0);
    });

    it('ein neuer Start ersetzt den alten Lauf, dessen Antwort verfällt', async () => {
        const clock = fakeClock();
        const events = [];
        const releases = [];
        const poller = createScanPoller({
            read: () => new Promise((resolve) => releases.push(resolve)),
            onDone: (reading) => events.push(reading.scanState),
            onError: () => {},
            onTimeout: () => {},
            schedule: clock.schedule,
            cancel: clock.cancel,
        });

        poller.start();
        clock.pending()[0].callback();
        poller.start();
        releases[0]({ ok: true, scanState: 'idle' });
        await new Promise((resolve) => setImmediate(resolve));

        assert.deepEqual(events, [], 'Die Antwort des ersten Laufs gehört nicht mehr dazu.');
        assert.equal(poller.isRunning(), true);
    });

    it('läuft nach dem Ende erneut startbar', async () => {
        const { poller, clock, events } = setup([idle]);

        poller.start();
        await clock.fire();
        poller.start();
        await clock.fire();

        assert.deepEqual(events, [['done', 'idle'], ['done', 'idle']]);
    });
});
