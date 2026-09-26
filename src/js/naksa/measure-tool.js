/**
 * Standalone "measure a distance" ruler — click two points anywhere on the
 * canvas and read the distance back, independent of plot drawing or
 * calibration. Purely transient/informational: it never touches
 * state.plots or history, so there's nothing to undo.
 */
import { formatLengthInUnit } from './length-format.js';

export function createMeasureTool({ canvasEngine, store, onResult }) {
    const { fabricCanvas } = canvasEngine;
    let active = false;
    let firstPoint = null;
    let previewLine = null;
    let previewLabel = null;
    let unit = 'm';
    let lastPixelDistance = null;

    function clearPreview() {
        if (previewLine) { fabricCanvas.remove(previewLine); previewLine = null; }
        if (previewLabel) { fabricCanvas.remove(previewLabel); previewLabel = null; }
        fabricCanvas.requestRenderAll();
        lastPixelDistance = null;
    }

    function formatDistance(pixelDistance) {
        const mpp = store.getState().calibration && store.getState().calibration.metersPerPixel;
        if (!mpp) return `${pixelDistance.toFixed(0)} px (not calibrated)`;
        return formatLengthInUnit(pixelDistance * mpp, unit);
    }

    function setUnit(value) {
        unit = value;
        if (lastPixelDistance == null) return;
        const text = formatDistance(lastPixelDistance);
        if (previewLabel) { previewLabel.set({ text }); fabricCanvas.requestRenderAll(); }
        onResult?.(`Distance: ${text}`);
    }

    function start() {
        active = true;
        firstPoint = null;
        clearPreview();
        fabricCanvas.defaultCursor = 'crosshair';
        onResult?.('Click the distance\'s first point…');
    }

    function stop() {
        active = false;
        firstPoint = null;
        clearPreview();
        fabricCanvas.defaultCursor = 'default';
    }

    fabricCanvas.on('mouse:down', (opt) => {
        if (!active) return;
        const point = fabricCanvas.getPointer(opt.e);
        if (!firstPoint) {
            firstPoint = point;
            onResult?.('Click the distance\'s second point…');
            return;
        }
        const dist = Math.hypot(point.x - firstPoint.x, point.y - firstPoint.y);
        clearPreview();
        const mid = { x: (firstPoint.x + point.x) / 2, y: (firstPoint.y + point.y) / 2 };
        previewLine = new fabric.Line([firstPoint.x, firstPoint.y, point.x, point.y], {
            stroke: '#C8911A', strokeWidth: 2, strokeDashArray: [6, 4], selectable: false, evented: false
        });
        previewLabel = new fabric.Text(formatDistance(dist), {
            left: mid.x, top: mid.y - 14, fontSize: 12, fontFamily: 'Plus Jakarta Sans, sans-serif',
            fill: '#0C1C2A', backgroundColor: 'rgba(255,255,255,0.9)',
            originX: 'center', originY: 'center', selectable: false, evented: false
        });
        fabricCanvas.add(previewLine, previewLabel);
        fabricCanvas.requestRenderAll();
        lastPixelDistance = dist;
        onResult?.(`Distance: ${formatDistance(dist)}`);
        firstPoint = null;
    });

    return { start, stop, clear: clearPreview, isActive: () => active, setUnit };
}
