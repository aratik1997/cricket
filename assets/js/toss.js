(function () {
    const card = document.querySelector('.toss-card');
    if (!card) return;
    const matchId = card.dataset.match;
    const initialStatus = card.dataset.status;

    function wireButtons(selector, endpoint, buildBody) {
        const buttons = card.querySelectorAll(selector);
        if (!buttons.length) return false;
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                buttons.forEach((b) => (b.disabled = true));
                fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(buildBody(btn))
                })
                    .then((r) => r.json())
                    .then((data) => {
                        if (data.error) {
                            alert(data.error);
                            buttons.forEach((b) => (b.disabled = false));
                            return;
                        }
                        window.location.reload();
                    })
                    .catch(() => buttons.forEach((b) => (b.disabled = false)));
            });
        });
        return true;
    }

    const hasCallButtons = wireButtons('.toss-call-btn', '/cricket/api/toss_call.php', function (btn) {
        return { match_id: matchId, call: btn.dataset.call };
    });
    const hasChoiceButtons = !hasCallButtons && wireButtons('.toss-btn', '/cricket/api/toss_decision.php', function (btn) {
        return { match_id: matchId, decision: btn.dataset.decision };
    });

    if (!hasCallButtons && !hasChoiceButtons) {
        // Not our turn to act: poll until the status moves on.
        const poll = setInterval(function () {
            fetch('/cricket/api/match_state.php?match=' + matchId)
                .then((r) => r.json())
                .then((data) => {
                    if (data.status && data.status !== initialStatus) {
                        clearInterval(poll);
                        window.location.reload();
                    }
                })
                .catch(() => {});
        }, 1500);
    }
})();
