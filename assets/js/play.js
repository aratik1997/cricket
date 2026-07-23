(function () {
    const root = document.getElementById('play-root');
    if (!root) return;

    let spectatorPoll = null;

    function currentScreen() {
        return root.querySelector('.play-screen');
    }

    function reinit() {
        if (typeof window.initStopwatch === 'function') window.initStopwatch();
        if (typeof window.initPlayerPicker === 'function') window.initPlayerPicker();
        if (typeof window.initTestMatch === 'function') window.initTestMatch();
        if (typeof window.initStatsModal === 'function') window.initStatsModal();
        bindHitZone();
    }

    // Swaps in the latest state for the match without a full page navigation. Only falls back to
    // reload() for genuine phase changes (innings/day/match over) that this screen can't render.
    function refresh() {
        const screen = currentScreen();
        const matchId = screen ? screen.dataset.match : null;
        if (!matchId) { window.location.reload(); return; }

        fetch('/cricket/api/match_fragment.php?match=' + matchId)
            .then((r) => r.json())
            .then((data) => {
                if (data.error || data.reload) { window.location.reload(); return; }
                root.innerHTML = data.html;
                reinit();
            })
            .catch(() => window.location.reload());
    }

    function bindHitZone() {
        if (spectatorPoll) {
            clearInterval(spectatorPoll);
            spectatorPoll = null;
        }

        const screen = currentScreen();
        if (!screen) return;
        const myTurn = screen.dataset.myTurn === '1';
        const mode = screen.dataset.mode;

        if (myTurn) {
            window.onHit = function (value) {
                const resultEl = document.getElementById('ball-result');
                fetch('/cricket/api/play_ball.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ match_id: screen.dataset.match, stopwatch_value: value })
                })
                    .then((r) => r.json())
                    .then((data) => {
                        if (data.error) {
                            if (resultEl) resultEl.textContent = data.error;
                            return;
                        }
                        let text = '';
                        if (data.outcome === 'wicket') text = 'OUT! Wicket.';
                        else if (data.outcome === 'dot') text = 'Dot ball.';
                        else text = '+' + data.runs + ' run(s)!';
                        if (resultEl) resultEl.textContent = text;
                        setTimeout(refresh, 900);
                    })
                    .catch(() => {
                        if (resultEl) resultEl.textContent = 'Network error, retrying...';
                        setTimeout(refresh, 1200);
                    });
            };

            const statusEl = document.getElementById('autoplay-status');
            const autoplayButtons = document.querySelectorAll('.autoplay-btn');
            const hitBtn = document.getElementById('hit-btn');
            autoplayButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const autoMode = btn.dataset.mode;
                    const oversInput = document.getElementById('autoplay-overs');
                    const overs = autoMode === 'custom'
                        ? parseInt(oversInput ? oversInput.value : '1', 10) || 1
                        : undefined;

                    autoplayButtons.forEach((b) => { b.disabled = true; });
                    if (hitBtn) hitBtn.disabled = true;
                    if (statusEl) statusEl.textContent = 'Playing...';

                    fetch('/cricket/api/autoplay.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ match_id: screen.dataset.match, mode: autoMode, overs })
                    })
                        .then((r) => r.json())
                        .then((data) => {
                            if (data.error && statusEl) {
                                statusEl.textContent = data.error;
                                autoplayButtons.forEach((b) => { b.disabled = false; });
                                if (hitBtn) hitBtn.disabled = false;
                                return;
                            }
                            refresh();
                        })
                        .catch(() => refresh());
                });
            });
        } else if (mode === 'online' || mode === 'test_online') {
            // Spectator: poll for state changes and refresh in place — no reload/flicker.
            const inningsId = screen.dataset.inningsId;
            const ballsBowled = screen.dataset.ballsBowled;
            spectatorPoll = setInterval(function () {
                fetch('/cricket/api/match_state.php?match=' + screen.dataset.match)
                    .then((r) => r.json())
                    .then((data) => {
                        if (data.error) return;
                        if (
                            data.status !== 'in_progress' ||
                            String(data.innings_id) !== String(inningsId) ||
                            String(data.balls_bowled) !== String(ballsBowled)
                        ) {
                            clearInterval(spectatorPoll);
                            spectatorPoll = null;
                            refresh();
                        }
                    })
                    .catch(() => {});
            }, 1500);
        }
    }

    bindHitZone();
})();
