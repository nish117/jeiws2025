/**
 * The tool's one core interaction: click to place points, drag any of them
 * to fix a misplaced click, close the chain into a polygon, see its area.
 *
 * Points are draggable as soon as they're placed — not only after the
 * shape is closed, which is a deliberate difference from how this kind of
 * tool is often built (dragging only after closing). Only the *active*
 * plot's points are draggable at a time, to avoid ambiguity when two
 * plots' points sit close together; other plots render as static shapes.
 *
 * Every closed plot — active or not — stays `evented` so hovering it (even
 * one you're not currently editing) reveals its area at its centroid. This
 * is what makes "point at any parcel on the sheet and see its size" work
 * without needing to first select it.
 */
import { calculateSideLengths, calculatePolygonArea, calculateCentroid, validatePolygon } from './geometry.js';
import { pixelsToMeters, convertPointsToRealWorld } from './calibration.js';
import { convertAreaToNepalUnits, formatRopaniSystem, formatBighaSystem } from './units.js';
import { formatLengthInUnit } from './length-format.js';
import { nextId, getActivePlot } from './state.js';

const VERTEX_RADIUS = 5;
const CLOSE_SNAP_PX = 14; // screen px at current zoom — converted to image px before use
const ACTIVE_COLOR = '#1B6799';
const INACTIVE_COLOR = 'rgba(110,126,138,0.55)';

export function createPlotTools({ canvasEngine, store, history }) {
    const { fabricCanvas } = canvasEngine;
    let mode = 'idle'; // 'idle' | 'drawing'
    let drawingPlotId = null;
    let objectsByPlot = new Map();
    let suppressRender = false;
    let lengthUnit = 'm';

    function screenPxToImagePx(px) {
        return px / (canvasEngine.getViewState().zoom || 1);
    }

    function metersPerPixelOrNull() {
        const c = store.getState().calibration;
        return c && c.metersPerPixel ? c.metersPerPixel : null;
    }

    function formatLength(pixelLength) {
        const mpp = metersPerPixelOrNull();
        return mpp ? formatLengthInUnit(pixelsToMeters(pixelLength, mpp), lengthUnit) : `${pixelLength.toFixed(0)} px`;
    }

    function setLengthUnit(value) {
        lengthUnit = value;
        render();
    }

    // ── Lifecycle ────────────────────────────────────────────────────────
    // `mode`/`drawingPlotId` are always updated BEFORE the store.setState()
    // call in each of these, never after: setState's subscribers (in
    // naksa-main.js, syncDrawUi() reads plotTools.isDrawing() reactively so
    // the toolbar buttons stay correct however a plot gets closed — most
    // often via a canvas click near the first point, not the Finish
    // button) run synchronously *during* setState, so anything they read
    // off this module's own local variables must already be current by
    // then, not updated on the line after.
    function startNewPlot() {
        history.commit();
        const plot = { id: nextId('plot'), name: `Plot ${(store.getState().plots || []).length + 1}`, points: [], closed: false };
        mode = 'drawing';
        drawingPlotId = plot.id;
        store.setState(s => ({ ...s, plots: [...s.plots, plot], activePlotId: plot.id }));
    }

    /** Returns true once the active plot is closed, false if it still needs more points. */
    function finishPlot() {
        if (!drawingPlotId) return true;
        const plot = (store.getState().plots || []).find(p => p.id === drawingPlotId);
        if (!plot || plot.points.length < 3) return false;
        history.commit();
        const finishedId = drawingPlotId;
        mode = 'idle';
        drawingPlotId = null;
        store.setState(s => ({ ...s, plots: s.plots.map(p => p.id === finishedId ? { ...p, closed: true } : p) }));
        return true;
    }

    function cancelPlot() {
        if (drawingPlotId) {
            history.commit();
            const cancelledId = drawingPlotId;
            mode = 'idle';
            drawingPlotId = null;
            store.setState(s => ({ ...s, plots: s.plots.filter(p => p.id !== cancelledId) }));
        } else {
            mode = 'idle';
            drawingPlotId = null;
        }
    }

    function deletePlot(plotId) {
        history.commit();
        store.setState(s => {
            const plots = s.plots.filter(p => p.id !== plotId);
            const activePlotId = s.activePlotId === plotId ? (plots[0] ? plots[0].id : null) : s.activePlotId;
            return { ...s, plots, activePlotId };
        });
    }

    function setActivePlot(plotId) {
        store.setState({ activePlotId: plotId });
    }

    function undoLastPoint() {
        if (!drawingPlotId) return;
        history.commit();
        store.setState(s => ({ ...s, plots: s.plots.map(p => p.id === drawingPlotId ? { ...p, points: p.points.slice(0, -1) } : p) }));
    }

    // ── Canvas click: place a new point while drawing ───────────────────
    fabricCanvas.on('mouse:down', (opt) => {
        if (mode !== 'drawing' || !drawingPlotId) return;
        const plot = (store.getState().plots || []).find(p => p.id === drawingPlotId);
        if (!plot) return;

        // A click landing directly on the first point's own circle (it's
        // evented so it can be dragged) also closes the loop — otherwise
        // the circle would swallow the click and the shape could never be
        // closed by clicking back on its own start.
        const hitVertex = opt.target && opt.target.data && opt.target.data.isVertex ? opt.target.data : null;
        if (hitVertex && hitVertex.plotId === drawingPlotId) {
            if (hitVertex.vertexIndex === 0 && plot.points.length >= 3) finishPlot();
            return;
        }

        const point = fabricCanvas.getPointer(opt.e);

        if (plot.points.length >= 3) {
            const first = plot.points[0];
            if (Math.hypot(point.x - first.x, point.y - first.y) <= screenPxToImagePx(CLOSE_SNAP_PX)) {
                finishPlot();
                return;
            }
        }

        history.commit();
        store.setState(s => ({ ...s, plots: s.plots.map(p => p.id === drawingPlotId ? { ...p, points: [...p.points, point] } : p) }));
    });

    // ── Rendering ────────────────────────────────────────────────────────
    function clearObjects(plotId) {
        const objs = objectsByPlot.get(plotId);
        if (!objs) return;
        objs.forEach(o => fabricCanvas.remove(o));
        objectsByPlot.delete(plotId);
    }
    function clearAll() {
        for (const id of Array.from(objectsByPlot.keys())) clearObjects(id);
    }

    function areaLabelText(plot) {
        const mpp = metersPerPixelOrNull();
        if (!mpp) return `${plot.name}\n(not calibrated)`;
        const real = convertPointsToRealWorld(plot.points, mpp);
        const areaSqM = calculatePolygonArea(real);
        const units = convertAreaToNepalUnits(areaSqM);
        return `${plot.name}\n${areaSqM.toFixed(2)} sq.m  (${units.sqft.toFixed(0)} sq.ft)`;
    }

    function renderPlot(plot) {
        const objs = [];
        const isActive = plot.id === store.getState().activePlotId;
        const points = plot.points;
        const color = isActive ? ACTIVE_COLOR : INACTIVE_COLOR;

        let shape = null;
        if (points.length >= 2) {
            const shapePoints = plot.closed ? [...points, points[0]] : points;
            shape = new fabric.Polyline(shapePoints, {
                fill: plot.closed ? (isActive ? 'rgba(27,103,153,0.14)' : 'rgba(110,126,138,0.10)') : 'transparent',
                stroke: color,
                strokeWidth: 2,
                strokeUniform: true,
                objectCaching: false,
                selectable: false,
                // Closed shapes stay evented even when inactive, so hovering
                // any plot (not just the selected one) reveals its area.
                evented: plot.closed,
                hoverCursor: plot.closed ? 'pointer' : 'default',
                data: { isPlotShape: true, plotId: plot.id }
            });
            if (plot.closed) {
                let hoverLabel = null;
                shape.on('mouseover', () => {
                    const centroid = calculateCentroid(points);
                    hoverLabel = new fabric.Text(areaLabelText(plot), {
                        left: centroid.x, top: centroid.y, fontSize: 13, fontFamily: 'Sora, sans-serif', fontWeight: '700',
                        fill: '#1B6799', backgroundColor: 'rgba(255,255,255,0.92)', textAlign: 'center',
                        originX: 'center', originY: 'center', selectable: false, evented: false
                    });
                    fabricCanvas.add(hoverLabel);
                    fabricCanvas.requestRenderAll();
                });
                shape.on('mouseout', () => {
                    if (hoverLabel) { fabricCanvas.remove(hoverLabel); hoverLabel = null; fabricCanvas.requestRenderAll(); }
                });
            }
            fabricCanvas.add(shape);
            objs.push(shape);
        }

        // Segment distance labels — active plot only, to keep a multi-plot
        // sheet from being covered in numbers; inactive plots rely on the
        // hover-to-reveal area instead.
        if (isActive && points.length >= 2) {
            // calculateSideLengths always includes the wrap-around (last->first)
            // segment; drop it while the chain is still open — that segment
            // isn't a real edge yet.
            const allSides = calculateSideLengths(points);
            const segments = plot.closed ? allSides : allSides.slice(0, points.length - 1);
            segments.forEach((side) => {
                const mid = { x: (side.from.x + side.to.x) / 2, y: (side.from.y + side.to.y) / 2 };
                const label = new fabric.Text(formatLength(side.length), {
                    left: mid.x, top: mid.y - 14, fontSize: 12, fontFamily: 'Plus Jakarta Sans, sans-serif',
                    fill: '#0C1C2A', backgroundColor: 'rgba(255,255,255,0.85)',
                    originX: 'center', originY: 'center', selectable: false, evented: false
                });
                fabricCanvas.add(label);
                objs.push(label);
            });
        }

        // Vertices — draggable for the active plot, small static dots otherwise.
        points.forEach((pt, idx) => {
            const circle = new fabric.Circle({
                left: pt.x, top: pt.y, radius: VERTEX_RADIUS,
                fill: color, stroke: '#fff', strokeWidth: 1.5,
                originX: 'center', originY: 'center',
                hasControls: false, hasBorders: false,
                selectable: isActive, evented: isActive,
                hoverCursor: isActive ? 'move' : 'default',
                data: { isVertex: true, plotId: plot.id, vertexIndex: idx }
            });
            if (isActive) wireVertexDrag(circle, plot, shape);
            fabricCanvas.add(circle);
            objs.push(circle);
        });

        objectsByPlot.set(plot.id, objs);
    }

    function wireVertexDrag(circle, plot, shape) {
        circle.on('moving', () => {
            if (!shape) return;
            const idx = circle.data.vertexIndex;
            const pts = shape.points;
            pts[idx] = { x: circle.left, y: circle.top };
            if (plot.closed && idx === 0) pts[pts.length - 1] = { x: circle.left, y: circle.top };
            shape.dirty = true;
            fabricCanvas.requestRenderAll();
        });
        circle.on('modified', () => {
            history.commit();
            suppressRender = true;
            store.setState(s => ({
                ...s,
                plots: s.plots.map(p => p.id === plot.id
                    ? { ...p, points: p.points.map((pt, i) => i === circle.data.vertexIndex ? { x: circle.left, y: circle.top } : pt) }
                    : p)
            }));
            suppressRender = false;
        });
    }

    function render() {
        if (suppressRender) return;
        clearAll();
        for (const plot of store.getState().plots || []) renderPlot(plot);
        fabricCanvas.requestRenderAll();
    }

    const unsubscribe = store.subscribe(() => render());

    return {
        startNewPlot,
        finishPlot,
        cancelPlot,
        deletePlot,
        setActivePlot,
        undoLastPoint,
        render,
        setLengthUnit,
        isDrawing: () => mode === 'drawing',
        getValidation: () => {
            const plot = getActivePlot(store.getState());
            return plot ? validatePolygon(plot.points) : null;
        },
        destroy() {
            unsubscribe();
            clearAll();
        }
    };
}

/** Formats a plot's area in every Nepal unit at once, for the right-panel list/summary — reused by naksa-main.js. */
export function formatPlotAreaSummary(plot, metersPerPixel) {
    if (!plot.closed || plot.points.length < 3) return null;
    if (!metersPerPixel) return null;
    const real = convertPointsToRealWorld(plot.points, metersPerPixel);
    const areaSqM = calculatePolygonArea(real);
    const units = convertAreaToNepalUnits(areaSqM);
    return {
        sqm: units.sqm, sqft: units.sqft, sqyd: units.sqyd,
        ropani: formatRopaniSystem(units.ropaniSystem),
        bigha: formatBighaSystem(units.bighaSystem)
    };
}
