/**
 * Entry point: wires state/history, the Fabric canvas engine, upload,
 * calibration, the plot tool, the measure tool, and the results panel to
 * the naksa-analyzer.html DOM. Loaded as a `type="module"` script, after
 * the classic-script `fabric.min.js` tag in the page head.
 */
import { Store, createInitialState, getActivePlot } from './state.js';
import { History } from './history.js';
import { processUploadedFile, initUploadZone } from './upload.js';
import { createCanvasEngine } from './canvas-setup.js';
import { attachTouchGestures } from './touch-gestures.js';
import { createCrosshair } from './crosshair.js';
import { createMagnifier } from './magnifier.js';
import { createPlotTools, formatPlotAreaSummary } from './plot-tools.js';
import { createMeasureTool } from './measure-tool.js';
import { createUiLayout, createHelpModal, createCollapsibleSections } from './ui-layout.js';
import { calibrateFromTwoPoints, getCalibrationWarnings, calibrationStatusLabel } from './calibration.js';
import * as Geometry from './geometry.js';

function byId(id) { return document.getElementById(id); }
function fmt(n, decimals = 2) {
    return Number(n).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}
function escapeAttr(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

function main() {
    if (typeof fabric === 'undefined') {
        const container = byId('naksaCanvasContainer');
        if (container) {
            container.innerHTML = '<div class="naksa-canvas-empty-state"><i class="fas fa-triangle-exclamation"></i><p>The map viewer library failed to load. Check your internet connection and reload the page.</p></div>';
        }
        return;
    }

    const store = new Store(createInitialState());
    const history = new History(store);

    const canvasEngine = createCanvasEngine({
        canvasEl: byId('naksaCanvas'),
        containerEl: byId('naksaCanvasContainer')
    });
    attachTouchGestures(canvasEngine, byId('naksaCanvasContainer'));
    const crosshair = createCrosshair(byId('naksaCanvasContainer'));
    const magnifier = createMagnifier({ canvasEngine, containerEl: byId('naksaCanvasContainer') });

    // ── Section visibility follows whether an image is loaded ──────────
    const gatedSections = ['naksaCalibrationSection', 'naksaToolsSection', 'naksaViewSection'];
    function updateGatedSections(state) {
        const hasImage = !!state.image;
        gatedSections.forEach(id => { const el = byId(id); if (el) el.hidden = !hasImage; });
        byId('naksaCanvasEmptyState').hidden = hasImage;
    }

    // ── Upload wiring ────────────────────────────────────────────────────
    const uploadErrorEl = byId('naksaUploadError');
    function showUploadError(message) {
        uploadErrorEl.textContent = message;
        uploadErrorEl.hidden = false;
    }
    function clearUploadError() { uploadErrorEl.hidden = true; }

    async function handleFile(file) {
        clearUploadError();
        try {
            const image = await processUploadedFile(file);
            history.commit();
            store.setState(s => ({ ...s, image, calibration: null, plots: [], activePlotId: null }));
            byId('naksaFilePreviewImg').src = image.dataUrl;
            byId('naksaFilePreviewName').textContent = `${image.name} (${image.width}×${image.height}px)`;
            byId('naksaFilePreviewWrap').hidden = false;
            sections.advance('naksaUploadSection', 'naksaCalibrationSection');
            uiLayout.setMobileView('map');
        } catch (err) {
            showUploadError(err.message || 'Could not process this file.');
            // On phones the error lives in the Tools panel — switch there so it's actually seen.
            uiLayout.setMobileView('tools');
        }
    }

    initUploadZone({
        dropZoneEl: byId('naksaDropZone'),
        fileInputEl: byId('naksaFileInput'),
        onFile: handleFile
    });
    // Phones open on the Map tab, where the upload section isn't visible — give
    // the empty canvas its own button straight into the file picker.
    byId('naksaEmptyUploadBtn').addEventListener('click', () => byId('naksaFileInput').click());

    document.addEventListener('paste', (e) => {
        const target = e.target;
        const isTypingField = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable);
        if (isTypingField) return;
        const items = e.clipboardData && e.clipboardData.items;
        if (!items) return;
        for (const item of items) {
            if (item.type.startsWith('image/')) {
                const file = item.getAsFile();
                if (file) { e.preventDefault(); handleFile(file); }
                break;
            }
        }
    });

    byId('naksaRemoveFileBtn').addEventListener('click', () => {
        history.commit();
        store.setState({ image: null, calibration: null, plots: [], activePlotId: null });
        byId('naksaFilePreviewWrap').hidden = true;
        byId('naksaFileInput').value = '';
        sections.expand('naksaUploadSection');
    });

    // ── View controls ────────────────────────────────────────────────────
    byId('naksaZoomInBtn').addEventListener('click', () => canvasEngine.zoomIn());
    byId('naksaZoomOutBtn').addEventListener('click', () => canvasEngine.zoomOut());
    byId('naksaFitBtn').addEventListener('click', () => canvasEngine.fitToScreen());
    byId('naksaFullscreenBtn').addEventListener('click', () => canvasEngine.toggleFullscreen());

    let panActive = false;
    byId('naksaPanModeBtn').addEventListener('click', (e) => {
        panActive = !panActive;
        canvasEngine.setPanMode(panActive);
        e.currentTarget.classList.toggle('active', panActive);
    });

    byId('naksaUndoBtn').addEventListener('click', () => history.undo());
    byId('naksaRedoBtn').addEventListener('click', () => history.redo());
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !e.shiftKey) { e.preventDefault(); history.undo(); }
        if ((e.ctrlKey || e.metaKey) && (e.key.toLowerCase() === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey))) { e.preventDefault(); history.redo(); }
    });

    // Reload (or clear) the canvas image whenever it changes — covers upload and undo/redo alike.
    let lastImageRef = null;
    store.subscribe((state) => {
        if (state.image !== lastImageRef) {
            lastImageRef = state.image;
            if (state.image) canvasEngine.loadImage(state.image.dataUrl, state.image.width, state.image.height);
            else canvasEngine.clearImage();
        }
        updateGatedSections(state);
    });

    // ── Coordinate HUD ───────────────────────────────────────────────────
    const coordHud = byId('naksaCoordHud');
    canvasEngine.onCoordinateHover((point) => {
        const mpp = store.getState().calibration && store.getState().calibration.metersPerPixel;
        coordHud.hidden = false;
        coordHud.textContent = mpp
            ? `x: ${point.x.toFixed(0)}px (${(point.x * mpp).toFixed(2)}m)  y: ${point.y.toFixed(0)}px (${(point.y * mpp).toFixed(2)}m)`
            : `x: ${point.x.toFixed(0)}px  y: ${point.y.toFixed(0)}px`;
    });

    // ── Generic 2-click collector (used by calibration) ──────────────────
    let activeCollector = null;
    let collectorMarkers = [];
    function clearCollectorMarkers() {
        collectorMarkers.forEach(m => canvasEngine.fabricCanvas.remove(m));
        collectorMarkers = [];
        canvasEngine.fabricCanvas.requestRenderAll();
    }
    function collectClicks(count, { onProgress, onComplete }) {
        if (activeCollector) canvasEngine.fabricCanvas.off('mouse:down', activeCollector.handler);
        clearCollectorMarkers();
        const points = [];
        const handler = (opt) => {
            const point = canvasEngine.fabricCanvas.getPointer(opt.e);
            points.push(point);
            // A small visible marker at each clicked point — otherwise a
            // calibration click leaves no trace on the map and the user has
            // no way to confirm where the first point actually landed while
            // lining up the second.
            const marker = new fabric.Circle({
                left: point.x, top: point.y, radius: 5,
                fill: '#1B6799', stroke: '#fff', strokeWidth: 1.5,
                originX: 'center', originY: 'center',
                selectable: false, evented: false
            });
            canvasEngine.fabricCanvas.add(marker);
            collectorMarkers.push(marker);
            canvasEngine.fabricCanvas.requestRenderAll();
            onProgress?.(points.length, count);
            if (points.length >= count) {
                canvasEngine.fabricCanvas.off('mouse:down', handler);
                activeCollector = null;
                clearCollectorMarkers();
                onComplete(points);
            }
        };
        canvasEngine.fabricCanvas.on('mouse:down', handler);
        activeCollector = { handler };
    }

    // Only one click-driven interaction (plot drawing, a calibration pick,
    // or the measure tool) can listen for canvas clicks at a time.
    function isInteractionBusy() {
        return plotTools.isDrawing() || measureTool.isActive() || activeCollector !== null;
    }
    function warnInteractionBusy() {
        alert('Finish or cancel the current action first.');
    }
    // Shows a precision crosshair over the canvas for exactly as long as
    // one of the three point-picking tools above is actually active.
    function refreshPrecisionAids() {
        const busy = isInteractionBusy();
        crosshair.setActive(busy);
        magnifier.setActive(busy);
    }

    // ── Calibration (single method: known distance) ──────────────────────
    const FEET_TO_METERS = 0.3048;
    const calibDistanceInput = byId('naksaCalibDistanceInput');
    const calibDistanceLabel = byId('naksaCalibDistanceLabel');
    const calibDistanceLabelText = byId('naksaCalibDistanceLabelText');
    const calibFeetInchesRow = byId('naksaCalibFeetInchesRow');
    const calibFeetInput = byId('naksaCalibFeetInput');
    const calibInchesInput = byId('naksaCalibInchesInput');
    const calibStatus = byId('naksaCalibStatus');
    let calibUnit = 'm';

    function updateCalibUnitUi() {
        const isFeetInches = calibUnit === 'ft-in';
        calibFeetInchesRow.hidden = !isFeetInches;
        calibDistanceLabel.hidden = isFeetInches;
        if (!isFeetInches) calibDistanceLabelText.textContent = `Known real-world distance (${calibUnit})`;
    }
    updateCalibUnitUi();

    byId('naksaCalibUnitToggle').querySelectorAll('.naksa-unit-toggle-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            calibUnit = btn.dataset.unit;
            byId('naksaCalibUnitToggle').querySelectorAll('.naksa-unit-toggle-btn').forEach((b) => b.classList.toggle('active', b === btn));
            updateCalibUnitUi();
        });
    });

    // Reads whatever's in the currently-visible field(s) and converts to
    // metres, since calibrateFromTwoPoints (and everything downstream of
    // it) works in real-world metres regardless of what unit the user
    // found it easiest to enter the known distance in.
    function readCalibDistanceMeters() {
        if (calibUnit === 'ft-in') {
            const feet = Number(calibFeetInput.value) || 0;
            const inches = Number(calibInchesInput.value) || 0;
            if (feet <= 0 && inches <= 0) return null;
            return (feet + inches / 12) * FEET_TO_METERS;
        }
        const raw = Number(calibDistanceInput.value);
        if (!(raw > 0)) return null;
        if (calibUnit === 'ft') return raw * FEET_TO_METERS;
        if (calibUnit === 'cm') return raw / 100;
        return raw;
    }

    const calibBadge = byId('naksaCalibrationStatus');
    function updateCalibBadge(state) {
        const label = calibrationStatusLabel(state.calibration);
        const cls = !state.calibration ? 'not-calibrated' : label.includes('low confidence') ? 'low-confidence' : 'calibrated';
        calibBadge.textContent = label;
        calibBadge.className = `naksa-calib-badge ${cls}`;
    }

    function applyCalibration(result) {
        history.commit();
        store.setState({ calibration: result });
        const warnings = getCalibrationWarnings(result);
        calibStatus.textContent = `1 px = ${result.metersPerPixel.toFixed(6)} m${warnings.length ? ' — ' + warnings.join(' ') : ''}`;
        sections.advance('naksaCalibrationSection', 'naksaToolsSection');
    }

    byId('naksaCalibPickBtn').addEventListener('click', () => {
        if (isInteractionBusy()) { warnInteractionBusy(); return; }
        const distance = readCalibDistanceMeters();
        if (!(distance > 0)) { calibStatus.textContent = 'Enter the real-world distance first.'; return; }
        calibStatus.textContent = 'Click point 1 of 2…';
        collectClicks(2, {
            onProgress: (n) => { calibStatus.textContent = n < 2 ? 'Click point 2 of 2…' : 'Done.'; },
            onComplete: ([p1, p2]) => {
                try {
                    applyCalibration(calibrateFromTwoPoints(p1, p2, distance));
                } catch (err) {
                    calibStatus.textContent = err.message;
                } finally {
                    refreshPrecisionAids();
                }
            }
        });
        refreshPrecisionAids();
    });

    // ── Plot tools ───────────────────────────────────────────────────────
    const plotTools = createPlotTools({ canvasEngine, store, history });

    // Button visibility is a pure function of plotTools.isDrawing(), checked
    // on every store change — not just set once when a button is clicked.
    // Closing a plot normally happens via a canvas click (near the first
    // point), not the Finish button, so anything driven only by button
    // click handlers would leave New/Finish/Cancel/Undo stuck in
    // "drawing" mode after that kind of close.
    function syncDrawUi() {
        const drawing = plotTools.isDrawing();
        byId('naksaNewPlotBtn').hidden = drawing;
        byId('naksaFinishPlotBtn').hidden = !drawing;
        byId('naksaCancelPlotBtn').hidden = !drawing;
        byId('naksaUndoPointBtn').hidden = !drawing;
        refreshPrecisionAids();
    }

    byId('naksaNewPlotBtn').addEventListener('click', () => {
        if (isInteractionBusy()) { warnInteractionBusy(); return; }
        plotTools.startNewPlot();
        syncDrawUi();
    });
    byId('naksaFinishPlotBtn').addEventListener('click', () => {
        if (!plotTools.finishPlot()) alert('Add at least 3 points before finishing the plot.');
        syncDrawUi();
    });
    byId('naksaCancelPlotBtn').addEventListener('click', () => { plotTools.cancelPlot(); syncDrawUi(); });
    byId('naksaUndoPointBtn').addEventListener('click', () => plotTools.undoLastPoint());

    // ── Measure tool ─────────────────────────────────────────────────────
    const measureStatus = byId('naksaMeasureStatus');
    const measureBtn = byId('naksaMeasureBtn');
    const measureTool = createMeasureTool({
        canvasEngine, store,
        onResult: (text) => { measureStatus.textContent = text; }
    });
    measureBtn.addEventListener('click', () => {
        if (measureTool.isActive()) {
            measureTool.stop();
            measureBtn.classList.remove('active');
            measureStatus.textContent = '';
        } else {
            if (isInteractionBusy()) { warnInteractionBusy(); return; }
            measureTool.start();
            measureBtn.classList.add('active');
        }
        refreshPrecisionAids();
    });

    // Shared "Show lengths in" toggle — drives both the live plot-edge
    // labels shown while drawing a plot and the standalone measure tool,
    // so switching units affects whichever one is on screen.
    byId('naksaLengthUnitToggle').querySelectorAll('.naksa-unit-toggle-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            byId('naksaLengthUnitToggle').querySelectorAll('.naksa-unit-toggle-btn').forEach((b) => b.classList.toggle('active', b === btn));
            plotTools.setLengthUnit(btn.dataset.unit);
            measureTool.setUnit(btn.dataset.unit);
        });
    });

    // ── Plot list + selected-plot area (right panel) ─────────────────────
    function renderPlotList(state) {
        const list = byId('naksaPlotList');
        const mpp = state.calibration && state.calibration.metersPerPixel;
        list.innerHTML = (state.plots || []).map(p => {
            const validation = Geometry.validatePolygon(p.points);
            const summary = p.closed && validation.isValid ? formatPlotAreaSummary(p, mpp) : null;
            const areaText = summary ? `${fmt(summary.sqm, 1)} sq.m` : (p.closed ? (mpp ? '—' : 'not calibrated') : 'drawing…');
            return `
            <li class="${p.id === state.activePlotId ? 'active' : ''}">
                <button type="button" class="naksa-item-select" data-plot-id="${p.id}">
                    <strong>${escapeAttr(p.name)}</strong><span>${areaText}</span>
                </button>
                <button type="button" class="naksa-item-delete" data-delete-plot-id="${p.id}"><i class="fas fa-trash"></i></button>
            </li>`;
        }).join('') || '<li class="naksa-empty-note">No plots yet — click "Add Plot" to start.</li>';

        list.querySelectorAll('[data-plot-id]').forEach(btn => {
            btn.addEventListener('click', () => plotTools.setActivePlot(btn.dataset.plotId));
        });
        list.querySelectorAll('[data-delete-plot-id]').forEach(btn => {
            btn.addEventListener('click', () => plotTools.deletePlot(btn.dataset.deletePlotId));
        });
    }

    function renderAreaResults(state) {
        const els = { areaResults: byId('naksaAreaResults') };
        const plot = getActivePlot(state);
        if (!plot || !plot.closed || plot.points.length < 3) {
            els.areaResults.innerHTML = '<p class="naksa-empty-note">Add and close a plot to see its area.</p>';
            return;
        }
        const mpp = state.calibration && state.calibration.metersPerPixel;
        if (!mpp) {
            els.areaResults.innerHTML = '<p class="naksa-empty-note naksa-empty-note-warn"><i class="fas fa-ruler-combined"></i> Calibrate the scale to calculate real-world area.</p>';
            return;
        }
        const validation = Geometry.validatePolygon(plot.points);
        const summary = formatPlotAreaSummary(plot, mpp);
        els.areaResults.innerHTML = `
            ${!validation.isValid ? `<p class="naksa-empty-note naksa-empty-note-error"><i class="fas fa-circle-exclamation"></i> ${validation.errors.join(' ')}</p>` : ''}
            <div class="naksa-area-grid">
                <div class="naksa-area-figure naksa-area-figure-primary">
                    <span class="naksa-area-value">${fmt(summary.sqft, 1)}</span>
                    <span class="naksa-area-unit">sq. ft</span>
                </div>
                <div class="naksa-area-figure">
                    <span class="naksa-area-value">${fmt(summary.sqm, 2)}</span>
                    <span class="naksa-area-unit">sq. m</span>
                </div>
                <div class="naksa-area-figure">
                    <span class="naksa-area-value">${fmt(summary.sqyd, 2)}</span>
                    <span class="naksa-area-unit">sq. yd</span>
                </div>
            </div>
            <div class="naksa-unit-system">
                <h5>Hill / Kathmandu Valley system</h5>
                <p>${summary.ropani}</p>
            </div>
            <div class="naksa-unit-system">
                <h5>Terai system</h5>
                <p>${summary.bigha}</p>
            </div>
        `;
    }

    store.subscribe((state) => {
        renderPlotList(state);
        renderAreaResults(state);
        updateCalibBadge(state);
        syncDrawUi();
    });
    updateCalibBadge(store.getState());
    syncDrawUi();

    // ── UI layout (mobile tabs + help modal + collapsible left-panel steps) ──
    const uiLayout = createUiLayout({ rootEl: byId('naksaApp'), mobileTabButtons: Array.from(document.querySelectorAll('.naksa-mobile-toolbar button')) });
    createHelpModal({ overlayEl: byId('naksaHelpModal'), openTriggerEl: byId('naksaHelpBtn'), closeTriggerEl: byId('naksaHelpCloseBtn') });
    const sections = createCollapsibleSections(byId('naksaLeftPanel'));

    updateGatedSections(store.getState());
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', main);
} else {
    main();
}
