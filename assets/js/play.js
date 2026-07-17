(function () {
    const screen = document.querySelector('.play-screen');
    if (!screen) return;

    const matchId = screen.dataset.match;
    const myTurn = screen.dataset.myTurn === '1';
    const mode = screen.dataset.mode;
    const inningsId = screen.dataset.inningsId;
    const ballsBowled = screen.dataset.ballsBowled;

    if (myTurn) {
        window.onHit = function (value) {
            const resultEl = document.getElementById('ball-result');
            fetch('/cricket/api/play_ball.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ match_id: matchId, stopwatch_value: value })
            })
                .then((r) => r.json())
                .then((data) => {
                    if (data.error) {
                        resultEl.textContent = data.error;
                        return;
                    }
                    let text = '';
                    if (data.outcome === 'wicket') text = 'OUT! Wicket.';
                    else if (data.outcome === 'dot') text = 'Dot ball.';
                    else text = '+' + data.runs + ' run(s)!';
                    resultEl.textContent = text;
                    setTimeout(() => window.location.reload(), 1100);
                })
                .catch(() => {
                    resultEl.textContent = 'Network error, retrying...';
                    setTimeout(() => window.location.reload(), 1500);
                });
        };

        const statusEl = document.getElementById('autoplay-status');
        const autoplayButtons = document.querySelectorAll('.autoplay-btn');
        const hitBtn = document.getElementById('hit-btn');
        autoplayButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const autoMode = btn.dataset.mode;
                const overs = autoMode === 'custom'
                    ? parseInt(document.getElementById('autoplay-overs').value, 10) || 1
                    : undefined;

                autoplayButtons.forEach((b) => { b.disabled = true; });
                if (hitBtn) hitBtn.disabled = true;
                if (statusEl) statusEl.textContent = 'Playing...';

                fetch('/cricket/api/autoplay.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ match_id: matchId, mode: autoMode, overs })
                })
                    .then((r) => r.json())
                    .then((data) => {
                        if (data.error && statusEl) {
                            statusEl.textContent = data.error;
                            autoplayButtons.forEach((b) => { b.disabled = false; });
                            if (hitBtn) hitBtn.disabled = false;
                            return;
                        }
                        window.location.reload();
                    })
                    .catch(() => window.location.reload());
            });
        });
    } else if (mode === 'online') {
        // Spectator: poll for state changes and reload when something happened.
        const poll = setInterval(function () {
            fetch('/cricket/api/match_state.php?match=' + matchId)
                .then((r) => r.json())
                .then((data) => {
                    if (data.error) return;
                    if (
                        data.status === 'completed' ||
                        String(data.innings_id) !== String(inningsId) ||
                        String(data.balls_bowled) !== String(ballsBowled)
                    ) {
                        clearInterval(poll);
                        window.location.reload();
                    }
                })
                .catch(() => {});
        }, 1500);
    }
})();
