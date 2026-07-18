(function () {
    const batsmanSelect = document.getElementById('pick-batsman');
    const bowlerSelect = document.getElementById('pick-bowler');
    if (!batsmanSelect && !bowlerSelect) return;

    const screen = document.querySelector('.play-screen');
    const matchId = screen ? screen.dataset.match : null;
    const statusEl = document.getElementById('picker-status');

    function send(role, playerId) {
        if (statusEl) statusEl.textContent = 'Saving...';
        fetch('/cricket/api/set_next_player.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ match_id: matchId, role: role, player_id: parseInt(playerId, 10) || 0 })
        })
            .then((r) => r.json())
            .then((data) => {
                if (!statusEl) return;
                statusEl.textContent = data.error ? data.error : 'Saved — takes effect next time.';
            })
            .catch(() => {
                if (statusEl) statusEl.textContent = 'Network error.';
            });
    }

    if (batsmanSelect) {
        batsmanSelect.addEventListener('change', () => send('batsman', batsmanSelect.value));
    }
    if (bowlerSelect) {
        bowlerSelect.addEventListener('change', () => send('bowler', bowlerSelect.value));
    }
})();
