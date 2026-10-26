import template from './complianty-connector-page.html.twig';
import './complianty-connector-page.scss';
import { createScanPoller } from '../../scan-poller';
import { describeStartOutcome } from '../../scan-outcome';

Shopware.Component.register('complianty-connector-page', {
    template,

    inject: ['compliantyApi', 'acl'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            isLoading: true,
            isSaving: false,
            state: 'no_token',
            tokenHint: null,
            status: null,
            dse: null,
            isSyncing: false,
            scanStarting: false,
            scanPolling: false,
            scanMessage: null,
            tokenInput: '',
        };
    },

    computed: {
        isConnected() {
            return this.state !== 'no_token';
        },

        canEdit() {
            return this.acl.can('system_config:update');
        },

        stateClass() {
            if (this.state === 'ok') {
                return 'ok';
            }
            if (this.state === 'no_token') {
                return 'neutral';
            }
            return ['subscription_inactive', 'feature_not_available', 'rate_limited'].includes(this.state)
                ? 'gaps'
                : 'critical';
        },

        statusClass() {
            return {
                ok: 'ok',
                gaps: 'gaps',
                critical: 'critical',
                pending: 'neutral',
            }[this.status?.status] ?? 'neutral';
        },

        scanBusy() {
            return this.scanStarting || this.scanPolling || (this.status?.scan_state ?? 'idle') !== 'idle';
        },

        canStartScan() {
            return this.canEdit && this.status?.scan_trigger_allowed === true;
        },

        scanWaitText() {
            const wait = this.scanMessage?.wait;

            return wait ? this.$tc(`complianty-connector.scanWait.${wait.unit}`, wait.count, { count: wait.count }) : '';
        },

        scanMessageClass() {
            const classes = { info: 'neutral', warning: 'gaps', error: 'critical', success: 'ok' };

            return classes[this.scanMessage?.variant] ?? 'neutral';
        },

        dseClass() {
            return this.dse?.state === 'active' ? 'ok' : 'gaps';
        },

        dseChecked() {
            return this.dse ? this.formatDate(this.dse.checked_at) : null;
        },

        dseApproved() {
            return this.dse?.approved_at ? this.formatDate(this.dse.approved_at) : null;
        },

        lastScan() {
            const value = this.status?.last_scanned_at;
            if (!value) {
                return null;
            }

            return this.formatDate(value);
        },
    },

    created() {
        this.poller = createScanPoller({
            read: () => this.readScanState(),
            onDone: (reading) => this.finishScan(reading),
            onError: () => this.failScan('pollError'),
            onTimeout: () => this.failScan('timeout'),
        });
        this.load();
    },

    beforeUnmount() {
        this.poller.stop();
    },

    methods: {
        formatDate(value) {
            // vue-i18n knows the Administration language on every Shopware line. The session store of 6.6 is gone in 6.7.
            const locale = this.$i18n?.locale || undefined;

            return new Date(value).toLocaleString(locale);
        },

        /**
         * Läuft beim Öffnen der Seite schon ein Scan (zum Beispiel von einem anderen Admin gestartet), wird weiter gewartet.
         */
        resumeScanIfRunning() {
            if (this.poller.isRunning() || this.scanStarting) {
                return;
            }

            if ((this.status?.scan_state ?? 'idle') !== 'idle') {
                this.scanPolling = true;
                this.scanMessage = { variant: 'info', key: 'running', wait: null };
                this.poller.start({ ignoreIdlePolls: 0 });
            }
        },

        startScan() {
            this.scanStarting = true;
            this.scanMessage = null;

            return this.compliantyApi
                .startScan()
                .then((payload) => {
                    const outcome = describeStartOutcome(payload);
                    this.scanMessage = outcome.message;

                    if (outcome.polling) {
                        this.scanPolling = true;
                        this.poller.start({ ignoreIdlePolls: outcome.ignoreIdlePolls });
                    }
                })
                .catch(() => {
                    this.scanMessage = describeStartOutcome({ state: 'unreachable' }).message;
                })
                .finally(() => {
                    this.scanStarting = false;
                });
        },

        readScanState() {
            return this.compliantyApi
                .scanStatus()
                .then((payload) => ({
                    ok: payload.state === 'ok',
                    scanState: payload.scan_state,
                    lastScanError: payload.last_scan_error,
                }));
        },

        finishScan(reading) {
            this.scanPolling = false;
            this.scanMessage = reading.lastScanError
                ? { variant: 'error', key: 'lastError', wait: null, error: reading.lastScanError }
                : { variant: 'success', key: 'done', wait: null };

            return this.load();
        },

        failScan(key) {
            this.scanPolling = false;
            this.scanMessage = { variant: 'warning', key, wait: null };
        },

        syncDse() {
            this.isSyncing = true;
            return this.compliantyApi
                .syncDse()
                .then((payload) => {
                    this.dse = payload.dse;
                    if (payload.sync_state !== 'ok') {
                        this.createNotificationError({
                            message: this.$tc(`complianty-connector.state.${payload.sync_state}`),
                        });
                    }
                })
                .finally(() => {
                    this.isSyncing = false;
                });
        },

        apply(payload) {
            this.state = payload.state;
            this.tokenHint = payload.token_hint;
            this.status = payload.status;
            this.dse = payload.dse;
            this.resumeScanIfRunning();
        },

        load() {
            this.isLoading = true;
            return this.compliantyApi
                .status()
                .then((payload) => this.apply(payload))
                .catch(() => {
                    this.state = 'unreachable';
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        connect() {
            this.isSaving = true;
            return this.compliantyApi
                .connect(this.tokenInput.trim())
                .then((payload) => {
                    this.apply(payload);
                    this.tokenInput = '';
                    this.createNotificationSuccess({ message: this.$tc('complianty-connector.notification.connected') });
                })
                .catch((error) => {
                    const state = error?.response?.data?.state ?? 'unreachable';
                    this.createNotificationError({ message: this.$tc(`complianty-connector.connectError.${state}`) });
                })
                .finally(() => {
                    this.isSaving = false;
                });
        },

        disconnect() {
            this.isSaving = true;
            return this.compliantyApi
                .disconnect()
                .then((payload) => this.apply(payload))
                .finally(() => {
                    this.isSaving = false;
                });
        },
    },
});
