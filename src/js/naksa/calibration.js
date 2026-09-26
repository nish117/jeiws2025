/**
 * Scale calibration math. Pure functions — no DOM, no canvas.
 *
 * All calibration lives in the uploaded image's own native pixel grid
 * (never on-screen CSS pixels), so the resulting metres-per-pixel value
 * keeps working no matter how far the user has zoomed/panned/rotated the
 * *view* afterward. The canvas layer (canvas-setup.js) is responsible for
 * feeding these functions native-image coordinates — that guarantee is
 * enforced by always reading points from Fabric's canvas.getPointer(),
 * which already returns object-space coordinates when the background image
 * is kept at an identity transform.
 */

export const CALIBRATION_METHODS = Object.freeze({
    RATIO: 'ratio',
    TWO_POINT: 'two_point',
    KNOWN_SIDE: 'known_side',
    SCALE_BAR: 'scale_bar'
});

export function pixelDistance(p1, p2) {
    return Math.hypot(p2.x - p1.x, p2.y - p1.y);
}

/**
 * Core calibration primitive every click-based method reduces to:
 * metresPerPixel = realDistance / pixelDistance(p1, p2).
 */
function calibrateFromPoints(p1, p2, realDistanceMeters, method) {
    const pxDist = pixelDistance(p1, p2);
    if (pxDist <= 0) {
        throw new Error('The two calibration points are identical — click two distinct points.');
    }
    if (!(realDistanceMeters > 0)) {
        throw new Error('Enter a real-world distance greater than zero.');
    }
    return {
        method,
        metersPerPixel: realDistanceMeters / pxDist,
        pixelDistance: pxDist,
        realDistanceMeters,
        points: [p1, p2]
    };
}

/** Method B: user clicks two arbitrary points and enters the real distance between them. */
export function calibrateFromTwoPoints(p1, p2, realDistanceMeters) {
    return calibrateFromPoints(p1, p2, realDistanceMeters, CALIBRATION_METHODS.TWO_POINT);
}

/** Method C: user picks an already-drawn boundary edge and enters its known real length. */
export function calibrateFromKnownSide(p1, p2, realLengthMeters) {
    return calibrateFromPoints(p1, p2, realLengthMeters, CALIBRATION_METHODS.KNOWN_SIDE);
}

/** Method D: user clicks the two ends of a printed scale bar and enters its labelled length. */
export function calibrateFromScaleBar(p1, p2, scaleBarLengthMeters) {
    return calibrateFromPoints(p1, p2, scaleBarLengthMeters, CALIBRATION_METHODS.SCALE_BAR);
}

/**
 * Method A: a printed ratio like 1:500 alone cannot calibrate pixels — it
 * describes the *drawing*, not the *scan*. It additionally needs a scan
 * resolution (dots per inch) to know how many pixels correspond to one
 * printed inch. Common flatbed scans of Naapi sheets are done at a known
 * DPI (300/600 are typical); if the user doesn't know it, steer them to the
 * scale-bar method instead, which needs no such assumption.
 *
 *   metresPerPixel = (1 / dpi) inches-per-pixel * 0.0254 m/inch * ratioDenominator
 */
export function calibrateFromRatio(ratioDenominator, dpi) {
    if (!(ratioDenominator > 0)) throw new Error('Enter a valid scale ratio denominator, e.g. 500 for 1:500.');
    if (!(dpi > 0)) throw new Error('Enter the scan/image resolution in DPI to anchor the ratio to pixels.');
    const metersPerPixel = (1 / dpi) * 0.0254 * ratioDenominator;
    return {
        method: CALIBRATION_METHODS.RATIO,
        metersPerPixel,
        ratioDenominator,
        dpi
    };
}

/** Convert a distance already measured in native image pixels to metres. */
export function pixelsToMeters(pixelValue, metersPerPixel) {
    return pixelValue * metersPerPixel;
}

export function metersToPixels(meterValue, metersPerPixel) {
    return meterValue / metersPerPixel;
}

/** Maps an array of native-pixel {x,y} points to calibrated real-world metre coordinates. */
export function convertPointsToRealWorld(pixelPoints, metersPerPixel) {
    return pixelPoints.map(p => ({ x: p.x * metersPerPixel, y: p.y * metersPerPixel }));
}

/**
 * Sanity/confidence checks on a calibration result. Returns a list of
 * human-readable warning strings (empty = no concerns). Never blocks the
 * calibration from being used — the spec asks that we surface confidence
 * issues, not refuse a user's explicit input.
 */
export function getCalibrationWarnings(calibration) {
    const warnings = [];
    if (!calibration || !calibration.metersPerPixel) return warnings;

    if (typeof calibration.pixelDistance === 'number' && calibration.pixelDistance < 20) {
        warnings.push('The two calibration points are very close together on screen — pick two points further apart for better accuracy.');
    }
    if (calibration.metersPerPixel < 0.0005 || calibration.metersPerPixel > 50) {
        warnings.push('The computed scale looks unusual for a land survey map — double-check the entered real-world distance and units.');
    }
    return warnings;
}

export function calibrationStatusLabel(calibration) {
    if (!calibration || !calibration.metersPerPixel) return 'Not calibrated';
    return getCalibrationWarnings(calibration).length > 0 ? 'Calibrated (low confidence)' : 'Calibrated';
}
