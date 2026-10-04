/**
 * App-shell behaviour: sidebar toggle (collapse on desktop, off-canvas on
 * mobile), tooltips for the collapsed sidebar, and notification read state.
 */
(function () {
    'use strict';

    const body = document.body;
    const toggle = document.getElementById('accSidebarToggle');
    const backdrop = document.getElementById('accSidebarBackdrop');
    const desktopQuery = window.matchMedia('(min-width: 992px)');
    const STORAGE_KEY = 'accSidebarCollapsed';
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    function readCollapsedPref() {
        try { return localStorage.getItem(STORAGE_KEY) === '1'; } catch { return false; }
    }
    function writeCollapsedPref(value) {
        try { localStorage.setItem(STORAGE_KEY, value ? '1' : '0'); } catch { /* storage blocked */ }
    }

    // Tooltips only make sense while the desktop sidebar is icon-only.
    let tooltips = [];
    function syncTooltips() {
        tooltips.forEach(t => t.dispose());
        tooltips = [];
        if (desktopQuery.matches && body.classList.contains('acc-sidebar-collapsed') && window.bootstrap) {
            tooltips = Array.from(document.querySelectorAll('.acc-nav-link[data-bs-title]'))
                .map(el => new bootstrap.Tooltip(el, { placement: 'right', trigger: 'hover' }));
        }
    }

    function closeMobileSidebar() {
        body.classList.remove('acc-sidebar-open');
        toggle?.setAttribute('aria-expanded', 'false');
    }

    if (readCollapsedPref()) body.classList.add('acc-sidebar-collapsed');
    syncTooltips();

    toggle?.addEventListener('click', () => {
        if (desktopQuery.matches) {
            const collapsed = body.classList.toggle('acc-sidebar-collapsed');
            writeCollapsedPref(collapsed);
            syncTooltips();
        } else {
            const open = body.classList.toggle('acc-sidebar-open');
            toggle.setAttribute('aria-expanded', String(open));
        }
    });
    backdrop?.addEventListener('click', closeMobileSidebar);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMobileSidebar(); });
    desktopQuery.addEventListener('change', () => { closeMobileSidebar(); syncTooltips(); });

    // ── Notifications ──────────────────────────────────────────────
    document.getElementById('accMarkAllRead')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        try {
            const res = await fetch('notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
                body: new URLSearchParams({ action: 'mark_all_read', csrf_token: csrfToken })
            });
            if (!res.ok) throw new Error();
            document.querySelectorAll('.acc-notif-item.unread').forEach(el => el.classList.remove('unread'));
            document.getElementById('accNotifCount')?.remove();
            btn.remove();
        } catch {
            btn.disabled = false;
        }
    });
})();
