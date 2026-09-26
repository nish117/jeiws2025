/**
 * A circular magnifying loupe that follows the cursor while a precision
 * point-picking tool is active — shows a zoomed crop of the SOURCE image
 * at its native resolution (via canvas drawImage's source-rect scaling),
 * not just an enlargement of the already on-screen-scaled render. That
 * matters: if the user has zoomed the main view out to see the whole
 * sheet, magnifying the rendered pixels would only show blocky enlarged
 * blur, whereas sampling the original image still reveals real detail —
 * the whole point of a precision aid.
 */
export function createMagnifier({ canvasEngine, containerEl }) {
    const SIZE = 160; // on-screen diameter, px
    const EXTRA_ZOOM = 3; // magnification on top of whatever the main view is currently zoomed to
    const OFFSET = 24; // gap between the cursor and the loupe, so the loupe never covers the exact point being clicked

    const canvas = document.createElement('canvas');
    canvas.className = 'naksa-magnifier';
    canvas.width = SIZE;
    canvas.height = SIZE;
    containerEl.appendChild(canvas);
    const ctx = canvas.getContext('2d');

    let active = false;

    function hide() { canvas.style.display = 'none'; }
    function show() { canvas.style.display = 'block'; }
    hide();

    function draw(imagePoint, screenPoint) {
        const bgImage = canvasEngine.getBackgroundImage();
        if (!bgImage) { hide(); return; }
        const sourceEl = bgImage.getElement(); // native-resolution <img>/<canvas> behind the Fabric object
        const viewZoom = canvasEngine.getViewState().zoom || 1;
        const sampleRadius = (SIZE / 2) / (viewZoom * EXTRA_ZOOM);

        ctx.clearRect(0, 0, SIZE, SIZE);
        ctx.save();
        ctx.beginPath();
        ctx.arc(SIZE / 2, SIZE / 2, SIZE / 2 - 2, 0, Math.PI * 2);
        ctx.clip();
        ctx.fillStyle = '#f0ece6';
        ctx.fillRect(0, 0, SIZE, SIZE);
        ctx.imageSmoothingEnabled = false; // nearest-neighbour: shows exact pixel edges rather than a blur, which is the point at high zoom
        ctx.drawImage(
            sourceEl,
            imagePoint.x - sampleRadius, imagePoint.y - sampleRadius, sampleRadius * 2, sampleRadius * 2,
            0, 0, SIZE, SIZE
        );
        ctx.restore();

        // Crosshair marking the exact centre point — that's what will register as the click.
        ctx.strokeStyle = 'rgba(220,38,38,0.9)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(SIZE / 2, SIZE / 2 - 10); ctx.lineTo(SIZE / 2, SIZE / 2 + 10);
        ctx.moveTo(SIZE / 2 - 10, SIZE / 2); ctx.lineTo(SIZE / 2 + 10, SIZE / 2);
        ctx.stroke();

        // Offset up-and-right from the cursor by default, clamped so it never runs off the container.
        const containerRect = containerEl.getBoundingClientRect();
        let left = screenPoint.x + OFFSET;
        let top = screenPoint.y - SIZE - OFFSET;
        if (left + SIZE > containerRect.width) left = screenPoint.x - SIZE - OFFSET;
        if (top < 0) top = screenPoint.y + OFFSET;
        left = Math.max(0, Math.min(left, containerRect.width - SIZE));
        top = Math.max(0, Math.min(top, containerRect.height - SIZE));
        canvas.style.left = `${left}px`;
        canvas.style.top = `${top}px`;
        show();
    }

    canvasEngine.fabricCanvas.on('mouse:move', (opt) => {
        if (!active) return;
        const imagePoint = canvasEngine.fabricCanvas.getPointer(opt.e);
        const rect = containerEl.getBoundingClientRect();
        draw(imagePoint, { x: opt.e.clientX - rect.left, y: opt.e.clientY - rect.top });
    });

    containerEl.addEventListener('mouseleave', hide);

    return {
        setActive(value) {
            active = value;
            if (!value) hide();
        }
    };
}
