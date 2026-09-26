/**
 * Two-finger pinch-zoom and two-finger pan for the canvas engine.
 * Fabric.js's open-source core has no built-in multi-touch gesture
 * recognizer (that only ever existed in a long-defunct commercial add-on),
 * so this listens to native touch events directly and drives the same
 * zoomAtScreenPoint()/pan primitives the mouse-wheel/drag handlers use.
 *
 * Single-finger tap/drag is left alone here — Fabric's own touch handling
 * (via its pointer-event support) already covers single-touch vertex
 * dragging and tapping for the drawing tools.
 */
export function attachTouchGestures(canvasEngine, targetEl) {
    let pinchStartDistance = null;
    let pinchStartZoom = null;
    let lastMidpoint = null;

    function getTouchPoint(touch, rect) {
        return { x: touch.clientX - rect.left, y: touch.clientY - rect.top };
    }

    function distanceBetween(t1, t2) {
        return Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY);
    }

    function midpoint(t1, t2, rect) {
        return {
            x: (t1.clientX + t2.clientX) / 2 - rect.left,
            y: (t1.clientY + t2.clientY) / 2 - rect.top
        };
    }

    targetEl.addEventListener('touchstart', (e) => {
        if (e.touches.length === 2) {
            e.preventDefault();
            const rect = targetEl.getBoundingClientRect();
            pinchStartDistance = distanceBetween(e.touches[0], e.touches[1]);
            pinchStartZoom = canvasEngine.getViewState().zoom;
            lastMidpoint = midpoint(e.touches[0], e.touches[1], rect);
        }
    }, { passive: false });

    targetEl.addEventListener('touchmove', (e) => {
        if (e.touches.length === 2 && pinchStartDistance) {
            e.preventDefault();
            const rect = targetEl.getBoundingClientRect();
            const currentDistance = distanceBetween(e.touches[0], e.touches[1]);
            const currentMidpoint = midpoint(e.touches[0], e.touches[1], rect);
            const scaleFactor = currentDistance / pinchStartDistance;

            // Two-finger drag: translate by however much the midpoint itself
            // moved since the last frame, before re-anchoring the zoom.
            if (lastMidpoint) {
                const dx = currentMidpoint.x - lastMidpoint.x;
                const dy = currentMidpoint.y - lastMidpoint.y;
                canvasEngine.panBy(dx, dy);
            }
            // Pinch zoom: anchor at the current midpoint so the gesture feels centered.
            canvasEngine.zoomAtScreenPoint(pinchStartZoom * scaleFactor, currentMidpoint);

            lastMidpoint = currentMidpoint;
        }
    }, { passive: false });

    targetEl.addEventListener('touchend', (e) => {
        if (e.touches.length < 2) {
            pinchStartDistance = null;
            pinchStartZoom = null;
            lastMidpoint = null;
        }
    });
}
