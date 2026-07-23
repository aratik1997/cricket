(function () {
    // Registered once — looks the modal up fresh each time so it survives DOM swaps.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const modal = document.getElementById('stats-modal');
        if (modal && !modal.hidden) modal.hidden = true;
    });

    window.initStatsModal = function () {
        const btn = document.getElementById('stats-btn');
        const modal = document.getElementById('stats-modal');
        const closeBtn = document.getElementById('stats-close');
        if (!btn || !modal) return;

        btn.addEventListener('click', () => { modal.hidden = false; });
        if (closeBtn) closeBtn.addEventListener('click', () => { modal.hidden = true; });
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.hidden = true;
        });
    };

    window.initStatsModal();
})();
