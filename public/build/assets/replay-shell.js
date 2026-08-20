(function (global) {
    'use strict';

    global.ReelReplayShell = function (configuration) {
        return {
            playerUrl: configuration.playerUrl,
            nonce: configuration.nonce,
            loaded: false,
            ready: false,
            status: 'Replay loading',
            diagnostic: '',
            time: 0,
            duration: 0,
            listener: null,

            init() {
                this.listener = (event) => {
                    const frame = this.$refs.frame;
                    const message = frame
                        ? global.ReelMessageChannel.acceptPlayerMessage(event, frame.contentWindow, this.nonce)
                        : null;
                    if (!message) return;
                    if (message.type === 'diagnostic') {
                        this.diagnostic = 'Replay unavailable: ' + message.code.replaceAll('_', ' ');
                        this.ready = false;
                        return;
                    }
                    if (message.type === 'ready') {
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

            load() {
                this.loaded = true;
            },

            destroy() {
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
