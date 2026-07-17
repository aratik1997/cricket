(function () {
    const btn = document.getElementById('stats-btn');
    const modal = document.getElementById('stats-modal');
    const closeBtn = document.getElementById('stats-close');
    if (!btn || !modal) return;

    btn.addEventListener('click', () => { modal.hidden = false; });
    closeBtn.addEventListener('click', () => { modal.hidden = true; });
    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.hidden = true;
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.hidden) modal.hidden = true;
    });
})();
