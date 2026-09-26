/**
 * A screen-space crosshair overlay shown while a precision point-picking
 * tool is active (drawing a plot, measuring a distance, or picking
 * calibration points) — two full-length guide lines through the cursor,
 * for lining up a click precisely against a printed edge on the map.
 *
 * Deliberately implemented as plain absolutely-positioned DOM elements,
 * not Fabric objects: the lines need to span the full visible container in
 * screen space regardless of the canvas's current zoom/pan, which plain
 * CSS positioning gives for free — a Fabric object would need converting
 * between the identity-transform object space calibration.js depends on
 * and the screen, for no benefit here since the lines carry no data of
 * their own.
 */
export function createCrosshair(containerEl) {
    const vertical = document.createElement('div');
    vertical.className = 'naksa-crosshair-line naksa-crosshair-v';
    const horizontal = document.createElement('div');
    horizontal.className = 'naksa-crosshair-line naksa-crosshair-h';
    containerEl.appendChild(vertical);
    containerEl.appendChild(horizontal);

    let active = false;

    function move(e) {
        if (!active) return;
        const rect = containerEl.getBoundingClientRect();
        vertical.style.left = `${e.clientX - rect.left}px`;
        horizontal.style.top = `${e.clientY - rect.top}px`;
    }

    function show() { vertical.style.display = 'block'; horizontal.style.display = 'block'; }
    function hide() { vertical.style.display = 'none'; horizontal.style.display = 'none'; }

    containerEl.addEventListener('mousemove', move);
    containerEl.addEventListener('mouseleave', hide);
    containerEl.addEventListener('mouseenter', () => { if (active) show(); });

    hide();

    return {
        /** Turns the crosshair (and the matching CSS cursor) on/off — call whenever a point-picking tool starts or stops. */
        setActive(value) {
            active = value;
            containerEl.classList.toggle('naksa-precision-cursor', value);
            if (!value) hide();
        }
    };
}
