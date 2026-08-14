const menu = document.querySelector('[data-menu]');
if (menu) {
    menu.addEventListener('click', () => document.body.classList.toggle('menu-open'));
}
document.querySelectorAll('.sidebar a').forEach((link) => {
    link.addEventListener('click', () => document.body.classList.remove('menu-open'));
});

const copy = document.querySelector('[data-copy]');
if (copy) {
    copy.addEventListener('click', async () => {
        await navigator.clipboard.writeText(copy.previousElementSibling.textContent);
        copy.textContent = 'Tersalin';
        setTimeout(() => { copy.textContent = 'Salin'; }, 1600);
    });
}

const computerStatus = document.querySelector('[data-computer-status]');
if (computerStatus) {
    const activeCount = document.querySelector('[data-active-count]');
    const totalCount = document.querySelector('[data-total-count]');
    const computerList = computerStatus.querySelector('[data-computer-list]');

    const renderComputers = (data) => {
        if (!Array.isArray(data.computers)) return;
        activeCount.textContent = data.active_count;
        totalCount.textContent = data.total_count;
        computerList.replaceChildren();

        if (!data.computers.length) {
            const empty = document.createElement('div');
            empty.className = 'empty';
            empty.textContent = 'Belum ada komputer yang mengirim heartbeat.';
            computerList.append(empty);
            return;
        }

        data.computers.forEach((computer) => {
            const row = document.createElement('article');
            const dot = document.createElement('span');
            dot.className = `computer-dot${computer.active ? ' online' : ''}`;

            const details = document.createElement('div');
            const name = document.createElement('strong');
            name.textContent = computer.name;
            const meta = document.createElement('small');
            const loginStatus = computer.active && !computer.interactive ? ' · Menunggu pengguna login' : '';
            meta.textContent = `Klien ${computer.version} · ${computer.last_seen_label}${loginStatus}`;
            details.append(name, meta);

            const badge = document.createElement('span');
            badge.className = computer.active ? 'active-badge' : 'muted-badge';
            badge.textContent = computer.interactive ? 'Siap Edge' : (computer.active ? 'Menyala' : 'Offline');
            row.append(dot, details, badge);
            computerList.append(row);
        });
    };

    const refreshComputerStatus = async () => {
        try {
            const response = await fetch(computerStatus.dataset.statusUrl, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (response.ok) renderComputers(await response.json());
        } catch (_) {
            // Keep the last known status visible while the panel is temporarily offline.
        }
    };

    refreshComputerStatus();
    setInterval(refreshComputerStatus, 60000);
}
