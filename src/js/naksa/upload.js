/**
 * File upload: drag-drop zone + browse button, validation, and a hard
 * downscale cap. Everything here is client-side only — nothing is ever
 * sent to a server.
 */

const ACCEPTED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
const MAX_FILE_SIZE_BYTES = 30 * 1024 * 1024; // 30 MB — generous for a scanned survey sheet
const MAX_LONG_EDGE_PX = 4000; // caps memory/perf and stays under mobile Safari's canvas backing-store limits

export function validateFile(file) {
    if (!file) return { ok: false, error: 'No file selected.' };
    const typeOk = ACCEPTED_TYPES.includes(file.type) ||
        /\.(jpe?g|png|webp)$/i.test(file.name || '');
    if (!typeOk) {
        return { ok: false, error: 'Unsupported file type. Upload a JPG, PNG, or WEBP image.' };
    }
    if (file.size > MAX_FILE_SIZE_BYTES) {
        return { ok: false, error: `File is too large (${(file.size / (1024 * 1024)).toFixed(1)} MB). Maximum is ${MAX_FILE_SIZE_BYTES / (1024 * 1024)} MB.` };
    }
    return { ok: true };
}

function readFileAsDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(new Error('Could not read the file.'));
        reader.readAsDataURL(file);
    });
}

function loadImageElement(src) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Could not decode the image.'));
        img.src = src;
    });
}

/**
 * Caps an image to MAX_LONG_EDGE_PX on its longest side. Large phone photos
 * (12MP+) cause visible jank panning/zooming a Fabric canvas and risk
 * exceeding mobile Safari's undocumented canvas pixel-area ceiling, so
 * every uploaded image passes through this before use.
 */
function downscaleToCanvas(source, sourceWidth, sourceHeight) {
    const longEdge = Math.max(sourceWidth, sourceHeight);
    const scale = longEdge > MAX_LONG_EDGE_PX ? MAX_LONG_EDGE_PX / longEdge : 1;
    const width = Math.round(sourceWidth * scale);
    const height = Math.round(sourceHeight * scale);
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    canvas.getContext('2d').drawImage(source, 0, 0, width, height);
    return { canvas, width, height };
}

/**
 * Processes an uploaded image File into a normalized working image.
 * Returns { dataUrl, width, height, name } ready to be stored as state.image.
 */
export async function processUploadedFile(file) {
    const validation = validateFile(file);
    if (!validation.ok) throw new Error(validation.error);

    const dataUrl = await readFileAsDataUrl(file);
    const img = await loadImageElement(dataUrl);
    const { canvas, width, height } = downscaleToCanvas(img, img.naturalWidth, img.naturalHeight);

    return {
        dataUrl: canvas.toDataURL('image/png'),
        width,
        height,
        name: file.name || 'uploaded-map'
    };
}

/**
 * Wires a drop-zone element + a file input together. `onFile` is called
 * with the raw File the moment one is selected/dropped — validation and
 * processing happen upstream in processUploadedFile so this stays a thin
 * DOM-wiring helper.
 */
export function initUploadZone({ dropZoneEl, fileInputEl, onFile }) {
    const openPicker = () => fileInputEl.click();

    dropZoneEl.addEventListener('click', openPicker);
    dropZoneEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openPicker(); }
    });

    ['dragenter', 'dragover'].forEach(evt => {
        dropZoneEl.addEventListener(evt, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZoneEl.classList.add('drag-active');
        });
    });
    ['dragleave', 'drop'].forEach(evt => {
        dropZoneEl.addEventListener(evt, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZoneEl.classList.remove('drag-active');
        });
    });
    dropZoneEl.addEventListener('drop', (e) => {
        const file = e.dataTransfer.files && e.dataTransfer.files[0];
        if (file) onFile(file);
    });

    fileInputEl.addEventListener('change', () => {
        const file = fileInputEl.files && fileInputEl.files[0];
        if (file) onFile(file);
        fileInputEl.value = ''; // allow re-selecting the same file later
    });
}
