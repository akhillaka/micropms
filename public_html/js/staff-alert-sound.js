/**
 * Staff / POS alert chime — lightweight Web Audio tones (no large WAV decode).
 * Replaces the previous Mixkit sample that could freeze the UI on decode/play.
 */
(function () {
    var COOLDOWN_MS = 1800;
    var ctx = null;
    var lastPlayAt = 0;

    function audioContext() {
        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return null;
        if (!ctx) ctx = new Ctx();
        return ctx;
    }

    function unlockAudio() {
        var ac = audioContext();
        if (ac && ac.state === 'suspended') {
            ac.resume().catch(function () {});
        }
        // Silent tick so the context is truly unlocked after a user gesture.
        if (ac) {
            try {
                var silent = ac.createBuffer(1, 1, ac.sampleRate);
                var src = ac.createBufferSource();
                src.buffer = silent;
                src.connect(ac.destination);
                src.start(0);
            } catch (e) {}
        }
        document.removeEventListener('pointerdown', unlockAudio);
        document.removeEventListener('keydown', unlockAudio);
    }
    document.addEventListener('pointerdown', unlockAudio, { passive: true });
    document.addEventListener('keydown', unlockAudio);

    /**
     * Short two-tone chime (similar feel to the old oscillator beep).
     */
    function playTone(ac) {
        var now = ac.currentTime;
        var master = ac.createGain();
        master.gain.setValueAtTime(0.0001, now);
        master.gain.exponentialRampToValueAtTime(0.22, now + 0.02);
        master.gain.exponentialRampToValueAtTime(0.0001, now + 0.75);
        master.connect(ac.destination);

        function beep(freq, start, dur) {
            var osc = ac.createOscillator();
            var g = ac.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, start);
            g.gain.setValueAtTime(0.0001, start);
            g.gain.exponentialRampToValueAtTime(0.9, start + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, start + dur);
            osc.connect(g);
            g.connect(master);
            osc.start(start);
            osc.stop(start + dur + 0.02);
        }

        beep(880, now, 0.18);
        beep(1174.66, now + 0.2, 0.35);
    }

    window.playStaffAlertSound = function playStaffAlertSound() {
        var nowMs = Date.now();
        if (nowMs - lastPlayAt < COOLDOWN_MS) {
            return;
        }

        var ac = audioContext();
        if (!ac) {
            return;
        }

        var run = function () {
            try {
                playTone(ac);
                lastPlayAt = Date.now();
            } catch (e) {
                console.warn('Alert chime failed:', e);
            }
        };

        if (ac.state === 'suspended') {
            ac.resume().then(run).catch(function () {});
        } else {
            run();
        }
    };
})();
