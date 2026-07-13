(function () {
    const card = document.getElementById('waiting-card');
    if (!card) return;
    const matchId = card.dataset.match;

    const poll = setInterval(function () {
        fetch('/cricket/api/match_state.php?match=' + matchId)
            .then((r) => r.json())
            .then((data) => {
                if (data.status && data.status !== 'waiting') {
                    clearInterval(poll);
                    window.location.reload();
                }
            })
            .catch(() => {});
    }, 2000);
})();
