/**
 * Fabric.js canvas engine: the interactive map workspace (zoom/pan,
 * fit/reset, fullscreen, cursor coordinate readout).
 *
 * Core architectural rule (this is what makes calibration.js's "always
 * native pixel space" guarantee true): the background image object is kept
 * at an identity transform FOREVER — left=0, top=0, scaleX=scaleY=1,
 * angle=0. Zoom and pan are implemented purely as edits to
 * canvas.viewportTransform, which every Fabric object (the image, plot
 * points/lines) shares — so they all pan/zoom together automatically, and
 * fabricCanvas.getPointer(event) always resolves back to native-image
 * pixel coordinates no matter the current view.
 */

function computeMatrix({ zoom, panX, panY }) {
    return [zoom, 0, 0, zoom, panX, panY];
}

const MIN_ZOOM = 0.05;
const MAX_ZOOM = 20;

export function createCanvasEngine({ canvasEl, containerEl }) {
    const fabricCanvas = new fabric.Canvas(canvasEl, {
        selection: false,
        preserveObjectStacking: true,
        stopContextMenu: true
    });

    const viewState = { zoom: 1, panX: 0, panY: 0 };
    let bgImage = null;
    let imageWidth = 0, imageHeight = 0;
    let panMode = false;
    let isPanning = false;
    let lastPanClientPos = null;

    function applyMatrix() {
        fabricCanvas.setViewportTransform(computeMatrix(viewState));
        fabricCanvas.requestRenderAll();
    }

    function resizeToContainer() {
        const rect = containerEl.getBoundingClientRect();
        fabricCanvas.setWidth(rect.width);
        fabricCanvas.setHeight(rect.height);
        fabricCanvas.requestRenderAll();
    }

    function fitViewToBounds({ minX, minY, width, height }) {
        const rect = containerEl.getBoundingClientRect();
        const padding = 32;
        const safeWidth = width || 1, safeHeight = height || 1;
        const scaleX = (rect.width - padding) / safeWidth;
        const scaleY = (rect.height - padding) / safeHeight;
        const zoom = Math.max(MIN_ZOOM, Math.min(scaleX, scaleY));
        viewState.zoom = zoom;
        viewState.panX = rect.width / 2 - (minX + safeWidth / 2) * zoom;
        viewState.panY = rect.height / 2 - (minY + safeHeight / 2) * zoom;
        applyMatrix();
    }

    function fitToScreen() {
        if (!imageWidth || !imageHeight) return;
        fitViewToBounds({ minX: 0, minY: 0, width: imageWidth, height: imageHeight });
    }

    function resetView() {
        fitToScreen();
    }

    function clampZoom(z) {
        return Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, z));
    }

    /** Zooms so that the given screen point stays visually fixed. */
    function zoomAtScreenPoint(newZoomRaw, screenPoint) {
        const newZoom = clampZoom(newZoomRaw);
        const oldMatrix = computeMatrix(viewState);
        const inv = fabric.util.invertTransform(oldMatrix);
        const objPt = fabric.util.transformPoint(screenPoint, inv);

        viewState.zoom = newZoom;
        viewState.panX = screenPoint.x - newZoom * objPt.x;
        viewState.panY = screenPoint.y - newZoom * objPt.y;
        applyMatrix();
    }

    /** Translates the view by a raw screen-space delta (e.g. from a drag or two-finger pan). */
    function panBy(dxScreen, dyScreen) {
        viewState.panX += dxScreen;
        viewState.panY += dyScreen;
        applyMatrix();
    }

    function zoomIn() {
        const center = { x: fabricCanvas.getWidth() / 2, y: fabricCanvas.getHeight() / 2 };
        zoomAtScreenPoint(viewState.zoom * 1.25, center);
    }

    function zoomOut() {
        const center = { x: fabricCanvas.getWidth() / 2, y: fabricCanvas.getHeight() / 2 };
        zoomAtScreenPoint(viewState.zoom / 1.25, center);
    }

    // ── Pan (drag) ──────────────────────────────────────────────────────
    function setPanMode(enabled) {
        panMode = enabled;
        fabricCanvas.defaultCursor = enabled ? 'grab' : 'default';
        fabricCanvas.selection = !enabled;
    }

    fabricCanvas.on('mouse:down', (opt) => {
        if (!panMode) return;
        isPanning = true;
        lastPanClientPos = { x: opt.e.clientX, y: opt.e.clientY };
        fabricCanvas.defaultCursor = 'grabbing';
    });
    fabricCanvas.on('mouse:move', (opt) => {
        if (!isPanning) return;
        const dx = opt.e.clientX - lastPanClientPos.x;
        const dy = opt.e.clientY - lastPanClientPos.y;
        lastPanClientPos = { x: opt.e.clientX, y: opt.e.clientY };
        viewState.panX += dx;
        viewState.panY += dy;
        applyMatrix();
    });
    fabricCanvas.on('mouse:up', () => {
        isPanning = false;
        if (panMode) fabricCanvas.defaultCursor = 'grab';
    });

    // Mouse-wheel zoom, centered on the cursor.
    fabricCanvas.on('mouse:wheel', (opt) => {
        const delta = opt.e.deltaY;
        const factor = delta > 0 ? 0.9 : 1.1;
        const pointer = fabricCanvas.getPointer(opt.e, true); // screen-space pointer
        zoomAtScreenPoint(viewState.zoom * factor, pointer);
        opt.e.preventDefault();
        opt.e.stopPropagation();
    });

    // ── Background image ────────────────────────────────────────────────
    function loadImage(dataUrl, width, height) {
        return new Promise((resolve) => {
            fabric.Image.fromURL(dataUrl, (img) => {
                if (bgImage) fabricCanvas.remove(bgImage);
                imageWidth = width;
                imageHeight = height;
                img.set({
                    left: 0, top: 0, scaleX: 1, scaleY: 1, angle: 0,
                    selectable: false, evented: false, hoverCursor: 'default'
                });
                bgImage = img;
                fabricCanvas.add(bgImage);
                fabricCanvas.sendToBack(bgImage);
                fitToScreen();
                resolve(bgImage);
            }, { crossOrigin: 'anonymous' });
        });
    }

    // ── Cursor coordinate readout (native image px + calibrated metres) ──
    let coordinateCallback = null;
    function onCoordinateHover(cb) { coordinateCallback = cb; }
    fabricCanvas.on('mouse:move', (opt) => {
        if (!coordinateCallback || !bgImage) return;
        const p = fabricCanvas.getPointer(opt.e);
        coordinateCallback(p);
    });

    // ── Fullscreen ───────────────────────────────────────────────────────
    async function toggleFullscreen() {
        if (!document.fullscreenElement) {
            await containerEl.requestFullscreen?.();
        } else {
            await document.exitFullscreen?.();
        }
    }
    document.addEventListener('fullscreenchange', () => {
        resizeToContainer();
        fitToScreen();
    });

    window.addEventListener('resize', resizeToContainer);
    resizeToContainer();

    return {
        fabricCanvas,
        loadImage,
        fitToScreen,
        fitViewToBounds,
        resetView,
        zoomIn,
        zoomOut,
        zoomAtScreenPoint,
        panBy,
        setPanMode,
        toggleFullscreen,
        onCoordinateHover,
        resizeToContainer,
        getImageSize: () => ({ width: imageWidth, height: imageHeight }),
        clearImage() {
            if (bgImage) { fabricCanvas.remove(bgImage); bgImage = null; }
            imageWidth = 0;
            imageHeight = 0;
            fabricCanvas.requestRenderAll();
        },
        getViewState: () => ({ ...viewState }),
        getBackgroundImage: () => bgImage,
        destroy() {
            window.removeEventListener('resize', resizeToContainer);
            fabricCanvas.dispose();
        }
    };
}
