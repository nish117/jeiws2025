/**
 * Desktop 3-panel <-> stacked-mobile layout switching. Desktop shows the
 * left tools panel, center canvas, and right results panel simultaneously;
 * on narrow screens only one is visible at a time, selected via a bottom
 * toolbar, since showing all three stacked at once on a phone would bury
 * the canvas below several screens of controls.
 */
export function createUiLayout({ rootEl, mobileTabButtons }) {
    function setMobileView(view) {
        rootEl.dataset.mobileView = view;
        mobileTabButtons.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.mobileTab === view);
        });
    }

    mobileTabButtons.forEach(btn => {
        btn.addEventListener('click', () => setMobileView(btn.dataset.mobileTab));
    });

    setMobileView('map');

    return { setMobileView };
}

/**
 * Collapsible accordion behavior for the left panel's `.naksa-tool-section`
 * elements. Each section that has a `.naksa-section-header` wrapper (see
 * naksa-analyzer.html) becomes click-to-toggle; a section without one is
 * left exactly as before (used for the right panel's results sections,
 * which stay always-expanded reference material rather than workflow
 * steps). `data-default="collapsed"` on the <section> picks its starting
 * state — everything else starts expanded.
 *
 * This exists because with every left-panel section always fully expanded
 * at once, the panel becomes a single long wall of ~10 stacked tool groups
 * (upload, perspective, view, calibration, drawing, lengths, plots,
 * annotations, north, display) that a user has to scroll past before
 * reaching whatever they actually need next — collapsing sections the
 * workflow has already moved past keeps only the relevant step in view.
 */
export function createCollapsibleSections(containerEl) {
    const sections = Array.from(containerEl.querySelectorAll(':scope > .naksa-tool-section'));

    sections.forEach(section => {
        const header = section.querySelector(':scope > .naksa-section-header');
        if (!header) return; // no wrapper — not collapsible, rendered as-is
        section.classList.toggle('naksa-collapsed', section.dataset.default === 'collapsed');
        header.addEventListener('click', () => section.classList.toggle('naksa-collapsed'));
    });

    function expand(id) {
        document.getElementById(id)?.classList.remove('naksa-collapsed');
    }
    function collapse(id) {
        document.getElementById(id)?.classList.add('naksa-collapsed');
    }
    /** Collapses `fromId` and expands `toId` in one call — the common "finished this step, move to the next" pattern. */
    function advance(fromId, toId) {
        collapse(fromId);
        expand(toId);
    }

    return { expand, collapse, advance };
}

export function createHelpModal({ overlayEl, openTriggerEl, closeTriggerEl }) {
    function open() { overlayEl.classList.add('open'); }
    function close() { overlayEl.classList.remove('open'); }
    openTriggerEl?.addEventListener('click', open);
    closeTriggerEl?.addEventListener('click', close);
    overlayEl?.addEventListener('click', (e) => { if (e.target === overlayEl) close(); });
    return { open, close };
}
