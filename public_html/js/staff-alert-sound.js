/**
 * Staff alert tone — louder and longer than a UI click.
 * Used by the bell menu and POS new-order polling.
 */
(function () {
    let unlocked = false;

    function unlockAudio() {
        if (unlocked) return;
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            if (ctx.state === 'suspended') {
                ctx.resume().catch(function () {});
            }
            const buf = ctx.createBuffer(1, 1, 22050);
            const src = ctx.createBufferSource();
            src.buffer = buf;
            src.connect(ctx.destination);
            src.start(0);
            unlocked = true;
        } catch (e) {}
        document.removeEventListener('pointerdown', unlockAudio);
        document.removeEventListener('keydown', unlockAudio);
    }
    document.addEventListener('pointerdown', unlockAudio);
    document.addEventListener('keydown', unlockAudio);

    function tone(ctx, freq, start, dur, gainValue, type) {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        const filter = ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.value = 2400;
        osc.type = type || 'square';
        osc.frequency.setValueAtTime(freq, start);
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(gainValue, start + 0.02);
        gain.gain.setValueAtTime(gainValue, start + Math.max(0.05, dur - 0.12));
        gain.gain.exponentialRampToValueAtTime(0.0001, start + dur);
        osc.connect(filter);
        filter.connect(gain);
        gain.connect(ctx.destination);
        osc.start(start);
        osc.stop(start + dur + 0.02);
    }

    window.playStaffAlertSound = function playStaffAlertSound() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            if (ctx.state === 'suspended') {
                ctx.resume().catch(function () {});
            }
            const t = ctx.currentTime;
            // ~1.7s two-burst chime, higher level than the old 0.12 sine blip
            tone(ctx, 880, t + 0.00, 0.32, 0.48, 'square');
            tone(ctx, 1174, t + 0.14, 0.38, 0.42, 'square');
            tone(ctx, 784, t + 0.52, 0.28, 0.45, 'square');
            tone(ctx, 988, t + 0.78, 0.42, 0.5, 'sawtooth');
            tone(ctx, 1318, t + 1.12, 0.55, 0.46, 'square');
            setTimeout(function () {
                if (ctx.state !== 'closed' && ctx.close) {
                    ctx.close().catch(function () {});
                }
            }, 2200);
        } catch (e) {
            console.warn('Alert sound failed:', e);
        }
    };
})();
