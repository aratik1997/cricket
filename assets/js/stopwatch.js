(function () {
    window.initStopwatch = function () {
        const display = document.getElementById('stopwatch');
        const hitBtn = document.getElementById('hit-btn');
        if (!display || !hitBtn) return;

        let running = true;
        let currentValue = 0;
        let lastTick = performance.now();
        const TICK_MS = 9; // fast enough to feel like a real reflex game

        function pad(n) {
            return n.toString().padStart(2, '0');
        }

        function loop(now) {
            if (!running) return;
            if (now - lastTick >= TICK_MS) {
                currentValue = (currentValue + 1) % 100;
                display.textContent = pad(currentValue);
                lastTick = now;
            }
            requestAnimationFrame(loop);
        }
        requestAnimationFrame(loop);

        hitBtn.addEventListener('click', function () {
            if (!running) return;
            running = false;
            hitBtn.disabled = true;
            const frozenValue = currentValue;
            display.textContent = pad(frozenValue);
            display.classList.add('frozen');
            if (typeof window.onHit === 'function') {
                window.onHit(frozenValue);
            }
        });
    };

    window.initStopwatch();
})();
