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

    // ── Confirm dialog ─────────────────────────────────────────────
    // <button data-confirm="Post this bill? It can't be edited afterwards."> or the same on a <form>.
    // The first sentence becomes the title, the rest the explanation; the OK button reuses the
    // trigger's label. Optional: data-confirm-ok="Label", data-confirm-variant="danger|primary",
    // data-confirm-reason="Prompt" on a form adds a required text box copied into its [name=reason].
    let modal = null;
    function confirmDialog(message, okLabel, danger, reasonLabel = '') {
        if (!modal) {
            const el = document.createElement('div');
            el.className = 'modal fade acc-confirm';
            el.tabIndex = -1;
            el.setAttribute('aria-hidden', 'true');
            el.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
                + '<div class="modal-body"><span class="acc-confirm-icon"><i class="fa-solid"></i></span>'
                + '<div class="flex-grow-1"><div class="acc-confirm-title" id="accConfirmTitle"></div><div class="acc-confirm-text"></div>'
                + '<div class="acc-confirm-reason mt-3"><label class="form-label small fw-semibold" for="accConfirmReason"></label>'
                + '<textarea class="form-control" id="accConfirmReason" rows="2" maxlength="250"></textarea></div></div></div>'
                + '<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>'
                + '<button type="button" class="btn acc-confirm-ok"></button></div></div></div>';
            el.setAttribute('aria-labelledby', 'accConfirmTitle');
            document.body.appendChild(el);
            modal = { el, bs: new bootstrap.Modal(el) };
        }
        const { el, bs } = modal;
        const split = message.match(/^(.+?[?.!])\s+(.+)$/s);
        el.querySelector('.acc-confirm-title').textContent = split ? split[1] : message;
        el.querySelector('.acc-confirm-text').textContent = split ? split[2] : '';
        el.classList.toggle('is-danger', danger);
        el.querySelector('.acc-confirm-icon i').className = 'fa-solid ' + (danger ? 'fa-triangle-exclamation' : 'fa-circle-question');
        const ok = el.querySelector('.acc-confirm-ok');
        ok.className = 'btn acc-confirm-ok ' + (danger ? 'btn-danger' : 'btn-primary');
        ok.textContent = okLabel;
        const reasonBox = el.querySelector('.acc-confirm-reason'), reason = el.querySelector('#accConfirmReason');
        reasonBox.hidden = !reasonLabel;
        reasonBox.querySelector('label').textContent = reasonLabel;
        reason.value = '';
        ok.disabled = !!reasonLabel;
        reason.oninput = () => { ok.disabled = reason.value.trim() === ''; };
        return new Promise(resolve => {
            let answer = false;
            const onOk = () => { answer = reasonLabel ? reason.value.trim() : true; ok.disabled = true; bs.hide(); };
            const onShown = () => (reasonLabel ? reason : ok).focus();
            ok.addEventListener('click', onOk, { once: true });
            el.addEventListener('shown.bs.modal', onShown, { once: true });
            el.addEventListener('hidden.bs.modal', () => { ok.removeEventListener('click', onOk); resolve(answer); }, { once: true });
            bs.show();
        });
    }

    const labelOf = el => (el?.dataset.confirmOk || el?.textContent || el?.value || '').trim().replace(/\s+/g, ' ');
    const isDanger = (trigger, button, message) => trigger.dataset.confirmVariant
        ? trigger.dataset.confirmVariant === 'danger'
        : /\bbtn-(outline-)?danger\b/.test(button?.className || '') || /^(delete|void|discard|remove|reopen|reset)\b/i.test(message);

    // Submit buttons: ask first, then click again for real so the button's name/value is still sent.
    document.addEventListener('click', async e => {
        const btn = e.target.closest('button[data-confirm], input[type=submit][data-confirm]');
        if (!btn || btn.disabled) return;
        if (btn.dataset.confirmed) { delete btn.dataset.confirmed; return; }
        e.preventDefault();
        e.stopPropagation();
        if (btn.form && !btn.formNoValidate && !btn.form.noValidate && !btn.form.checkValidity()) { btn.form.reportValidity(); return; }
        const msg = btn.dataset.confirm;
        if (await confirmDialog(msg, labelOf(btn) || 'Confirm', isDanger(btn, btn, msg))) {
            btn.dataset.confirmed = '1';
            btn.click();
        }
    }, true);

    // Whole forms (any submit button).
    document.addEventListener('submit', async e => {
        const form = e.target.closest('form[data-confirm]');
        if (!form) return;
        if (form.dataset.confirmed) { delete form.dataset.confirmed; return; }
        e.preventDefault();
        e.stopPropagation();
        const submitter = e.submitter || form.querySelector('[type=submit], button:not([type])');
        const msg = form.dataset.confirm;
        const answer = await confirmDialog(msg, labelOf(form.dataset.confirmOk ? form : submitter) || 'Confirm', isDanger(form, submitter, msg), form.dataset.confirmReason || '');
        if (answer) {
            if (typeof answer === 'string' && form.elements.reason) form.elements.reason.value = answer;
            form.dataset.confirmed = '1';
            if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
            else form.submit();
        }
    }, true);

    // ── Hover preview ──────────────────────────────────────────────
    // <tr data-peek="invoice-peek.php?id=5"> shows that URL's HTML in a floating card after a short
    // hover (mouse only — on touch screens a tap just opens the record). Responses are cached.
    const canHover = window.matchMedia('(hover: hover) and (pointer: fine)');
    const peekCache = new Map();
    let peekCard = null, peekTimer = null, hideTimer = null, peekRow = null, mouse = { x: 0, y: 0 };

    function peekEl() {
        if (!peekCard) {
            peekCard = document.createElement('div');
            peekCard.className = 'acc-peek';
            peekCard.setAttribute('role', 'tooltip');
            peekCard.addEventListener('mouseenter', () => clearTimeout(hideTimer));
            peekCard.addEventListener('mouseleave', () => hidePeek());
            document.body.appendChild(peekCard);
        }
        return peekCard;
    }
    function placePeek(row) {
        const card = peekEl(), gap = 10, r = row.getBoundingClientRect();
        const w = card.offsetWidth, h = card.offsetHeight, vw = window.innerWidth, vh = window.innerHeight;
        let x = Math.min(Math.max(mouse.x + 18, 12), vw - w - 12);
        let y = r.bottom + gap;
        if (y + h > vh - 12) y = r.top - h - gap;    // no room below: show above the row
        if (y < 12) y = Math.max(12, vh - h - 12);
        card.style.left = x + 'px';
        card.style.top = y + 'px';
    }
    async function showPeek(row) {
        const url = row.dataset.peek;
        const card = peekEl();
        let html = peekCache.get(url);
        if (html === undefined) {
            card.innerHTML = '<div class="acc-peek-loading"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
            card.classList.add('show');
            placePeek(row);
            try {
                const res = await fetch(url, { credentials: 'same-origin' });
                html = res.ok ? await res.text() : '';
            } catch { html = ''; }
            peekCache.set(url, html);
            if (peekRow !== row) return;    // pointer moved on while loading
        }
        if (!html) { hidePeek(true); return; }
        card.innerHTML = html;
        card.classList.add('show');
        placePeek(row);
        card.querySelector('img')?.addEventListener('load', () => peekRow === row && placePeek(row), { once: true });
    }
    function hidePeek(now = false) {
        clearTimeout(peekTimer);
        clearTimeout(hideTimer);
        const go = () => { peekRow = null; peekCard?.classList.remove('show'); };
        if (now) go(); else hideTimer = setTimeout(go, 120);
    }

    document.addEventListener('mousemove', e => { mouse = { x: e.clientX, y: e.clientY }; }, { passive: true });
    document.addEventListener('mouseover', e => {
        if (!canHover.matches) return;
        const row = e.target.closest('[data-peek]');
        if (!row || row === peekRow) return;
        hidePeek(true);
        peekRow = row;
        peekTimer = setTimeout(() => showPeek(row), 350);
    });
    document.addEventListener('mouseout', e => {
        const row = e.target.closest('[data-peek]');
        if (row && row === peekRow && !row.contains(e.relatedTarget) && !peekCard?.contains(e.relatedTarget)) hidePeek();
    });
    window.addEventListener('scroll', () => hidePeek(true), { passive: true, capture: true });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') hidePeek(true); });
})();
