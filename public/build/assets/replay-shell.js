(function (global) {
    'use strict';

    global.ReelReplayShell = function (configuration) {
        return {
            playerUrlEndpoint: configuration.playerUrlEndpoint,
            playerUrl: '',
            nonce: configuration.nonce,
            loaded: false,
            ready: false,
            status: 'Replay loading',
            diagnostic: '',
            time: 0,
            duration: 0,
            listener: null,
            readinessTimer: null,

            init() {
                this.listener = (event) => {
                    const frame = this.$refs.frame;
                    const message = frame
                        ? global.ReelMessageChannel.acceptPlayerMessage(event, frame.contentWindow, this.nonce)
                        : null;
                    if (!message) return;
                    if (message.type === 'diagnostic') {
                        this.fail(message.code);
                        return;
                    }
                    if (message.type === 'ready') {
                        this.clearReadinessTimer();
                        this.ready = true;
                        this.duration = message.duration;
                        this.status = 'Replay ready';
                        return;
                    }
                    this.status = message.status;
                    this.time = message.time;
                    this.duration = message.duration;
                };
                global.addEventListener('message', this.listener);
            },

            async load() {
                this.clearReadinessTimer();
                this.diagnostic = '';
                this.ready = false;
                this.status = 'Replay loading';

                try {
                    const response = await global.fetch(this.playerUrlEndpoint, {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    });
                    if (!response.ok) throw new Error('delivery_unavailable');
                    const payload = await response.json();
                    if (!payload || typeof payload.url !== 'string' || payload.url === '') {
                        throw new Error('delivery_unavailable');
                    }
                    this.playerUrl = payload.url;
                    this.readinessTimer = global.setTimeout(() => this.fail('player_timeout'), 10000);
                    this.loaded = true;
                } catch (_) {
                    this.fail('delivery_unavailable');
                }
            },

            clearReadinessTimer() {
                if (this.readinessTimer !== null) global.clearTimeout(this.readinessTimer);
                this.readinessTimer = null;
            },

            fail(code) {
                this.clearReadinessTimer();
                this.diagnostic = 'Replay unavailable: ' + code.replaceAll('_', ' ');
                this.status = this.diagnostic;
                this.ready = false;
                this.loaded = false;
            },

            destroy() {
                this.clearReadinessTimer();
                if (this.listener) global.removeEventListener('message', this.listener);
            },

            command(name, value) {
                const frame = this.$refs.frame;
                if (!this.ready || !frame) return;
                const message = arguments.length === 2
                    ? global.ReelMessageChannel.command(this.nonce, name, value)
                    : global.ReelMessageChannel.command(this.nonce, name);
                frame.contentWindow.postMessage(message, '*');
            },
        };
    };
}(window));
