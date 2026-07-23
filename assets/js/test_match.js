(function () {
    let dayTimerInterval = null;

    window.initTestMatch = function () {
        // Re-running this after every AJAX refresh — clear any previous countdown first so they
        // don't stack up and race each other.
        if (dayTimerInterval) {
            clearInterval(dayTimerInterval);
            dayTimerInterval = null;
        }

        // Live day-clock countdown on the play screen.
        const timerEl = document.getElementById('day-timer');
        const clockEl = document.getElementById('day-timer-clock');
        if (timerEl && clockEl) {
            let secondsLeft = parseInt(timerEl.dataset.seconds, 10) || 0;
            const render = () => {
                const m = Math.floor(secondsLeft / 60);
                const s = secondsLeft % 60;
                clockEl.textContent = m + ':' + String(s).padStart(2, '0');
            };
            render();
            dayTimerInterval = setInterval(() => {
                secondsLeft -= 1;
                if (secondsLeft <= 0) {
                    clearInterval(dayTimerInterval);
                    dayTimerInterval = null;
                    clockEl.textContent = "0:00 — stumps";
                    setTimeout(() => window.location.reload(), 1200);
                    return;
                }
                render();
            }, 1000);
        }

        // Randomized commentary hint shown before each hit — pure flavor, doesn't affect the real
        // outcome (that's still down to where you freeze the stopwatch).
        const hintEl = document.getElementById('ball-hint');
        if (hintEl) {
            const hints = [
                'This ball: no run expected.',
                'This ball: no wicket expected.',
                'Tight over so far — dot ball likely.',
                'Bowler’s found good rhythm here.',
                'Room for a quick single, perhaps.',
                'Fielders crowd the bat — stay alert.',
                'Anything could happen this ball…',
                'Batsman looks set for a big shot.',
                'Umpire’s watching closely.',
                'Classic Test match patience needed here.'
            ];
            hintEl.textContent = hints[Math.floor(Math.random() * hints.length)];
        }

        // Declare button (only present on the interactive play screen, not the Stumps gate).
        const declareBtn = document.getElementById('declare-btn');
        if (declareBtn) {
            const screen = document.querySelector('.play-screen');
            const matchId = screen ? screen.dataset.match : null;
            declareBtn.addEventListener('click', () => {
                if (!window.confirm('Declare the innings now?')) return;
                const statusEl = document.getElementById('declare-status');
                declareBtn.disabled = true;
                if (statusEl) statusEl.textContent = 'Declaring...';
                fetch('/cricket/api/declare.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ match_id: matchId })
                })
                    .then((r) => r.json())
                    .then((data) => {
                        if (data.error && statusEl) {
                            statusEl.textContent = data.error;
                            declareBtn.disabled = false;
                            return;
                        }
                        window.location.reload();
                    })
                    .catch(() => window.location.reload());
            });
        }

        // "Start Day N+1" gate (the Stumps screen shown once today's clock runs out).
        const advanceBtn = document.getElementById('advance-day-btn');
        if (advanceBtn) {
            const card = document.querySelector('.day-break-card');
            const matchId = card ? card.dataset.match : null;
            advanceBtn.addEventListener('click', () => {
                advanceBtn.disabled = true;
                advanceBtn.textContent = 'Starting...';
                fetch('/cricket/api/advance_day.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ match_id: matchId })
                })
                    .then(() => window.location.reload())
                    .catch(() => window.location.reload());
            });
        }
    };

    window.initTestMatch();
})();
