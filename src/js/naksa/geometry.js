/**
 * Pure polygon geometry helpers.
 *
 * Every function here operates on plain arrays of {x, y} points in a single
 * consistent linear unit (either pixels or already-calibrated real-world
 * metres — callers decide which by choosing which coordinates to pass in).
 * Nothing in this file touches the DOM, Fabric.js, or canvas state, so it can
 * be exercised directly from naksa-debug.html with zero setup, and reused
 * identically by the results panel, the PDF/CSV exporters, and the drawing
 * tools' live-length readouts.
 */

/**
 * Shoelace formula. Works for any simple (non-self-intersecting) polygon,
 * convex or concave, given vertices in either winding order. Returns the
 * area in the square of whatever linear unit the points are expressed in.
 */
export function calculatePolygonArea(points) {
    if (!Array.isArray(points) || points.length < 3) return 0;
    let sum = 0;
    const n = points.length;
    for (let i = 0; i < n; i++) {
        const p1 = points[i];
        const p2 = points[(i + 1) % n];
        sum += p1.x * p2.y - p2.x * p1.y;
    }
    return Math.abs(sum) / 2;
}

export function calculatePerimeter(points) {
    if (!Array.isArray(points) || points.length < 2) return 0;
    let total = 0;
    const n = points.length;
    for (let i = 0; i < n; i++) {
        const p1 = points[i];
        const p2 = points[(i + 1) % n];
        total += distance(p1, p2);
    }
    return total;
}

export function distance(p1, p2) {
    return Math.hypot(p2.x - p1.x, p2.y - p1.y);
}

/**
 * Returns one entry per edge: { index, from, to, length }.
 * Edge i runs from vertex i to vertex (i+1) % n, matching the winding order
 * the polygon was drawn in — so "Side 1" is always the first-drawn edge.
 */
export function calculateSideLengths(points) {
    if (!Array.isArray(points) || points.length < 2) return [];
    const n = points.length;
    const sides = [];
    for (let i = 0; i < n; i++) {
        const from = points[i];
        const to = points[(i + 1) % n];
        sides.push({ index: i, from, to, length: distance(from, to) });
    }
    return sides;
}

export function calculateCentroid(points) {
    if (!Array.isArray(points) || points.length === 0) return { x: 0, y: 0 };
    // Area-weighted centroid (correct for non-convex polygons); falls back
    // to the simple vertex average for degenerate near-zero-area shapes
    // (e.g. a line while the user is still drawing).
    const n = points.length;
    let area6 = 0, cx = 0, cy = 0;
    for (let i = 0; i < n; i++) {
        const p1 = points[i];
        const p2 = points[(i + 1) % n];
        const cross = p1.x * p2.y - p2.x * p1.y;
        area6 += cross;
        cx += (p1.x + p2.x) * cross;
        cy += (p1.y + p2.y) * cross;
    }
    const area = area6 / 2;
    if (Math.abs(area) < 1e-9) {
        const avg = points.reduce((acc, p) => ({ x: acc.x + p.x, y: acc.y + p.y }), { x: 0, y: 0 });
        return { x: avg.x / n, y: avg.y / n };
    }
    return { x: cx / (6 * area), y: cy / (6 * area) };
}

export function calculateBoundingBox(points) {
    if (!Array.isArray(points) || points.length === 0) {
        return { minX: 0, minY: 0, maxX: 0, maxY: 0, width: 0, height: 0 };
    }
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    for (const p of points) {
        if (p.x < minX) minX = p.x;
        if (p.x > maxX) maxX = p.x;
        if (p.y < minY) minY = p.y;
        if (p.y > maxY) maxY = p.y;
    }
    return { minX, minY, maxX, maxY, width: maxX - minX, height: maxY - minY };
}

/** Shortest and longest edge length, useful as a quick plot-shape summary. */
export function calculateMinMaxDimension(points) {
    const sides = calculateSideLengths(points);
    if (sides.length === 0) return { min: 0, max: 0 };
    const lengths = sides.map(s => s.length);
    return { min: Math.min(...lengths), max: Math.max(...lengths) };
}

/** Standard segment-intersection test (excludes shared endpoints). */
function segmentsIntersect(a1, a2, b1, b2) {
    const d1 = cross(b1, b2, a1);
    const d2 = cross(b1, b2, a2);
    const d3 = cross(a1, a2, b1);
    const d4 = cross(a1, a2, b2);
    if (((d1 > 0 && d2 < 0) || (d1 < 0 && d2 > 0)) &&
        ((d3 > 0 && d4 < 0) || (d3 < 0 && d4 > 0))) {
        return true;
    }
    return false;
}

function cross(o, a, b) {
    return (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x);
}

/**
 * Checks a closed polygon for self-intersection by testing every pair of
 * non-adjacent edges. O(n^2) — fine for the handful of vertices a hand-drawn
 * plot boundary realistically has.
 */
export function isPolygonSelfIntersecting(points) {
    const n = points.length;
    if (n < 4) return false;
    for (let i = 0; i < n; i++) {
        const a1 = points[i], a2 = points[(i + 1) % n];
        for (let j = i + 1; j < n; j++) {
            // Skip edges adjacent to edge i (they share a vertex, which is
            // not a crossing).
            if (j === i || j === (i + 1) % n || (j + 1) % n === i) continue;
            const b1 = points[j], b2 = points[(j + 1) % n];
            if (segmentsIntersect(a1, a2, b1, b2)) return true;
        }
    }
    return false;
}

/**
 * Full validity check used before any area/perimeter figure is trusted.
 * Returns { isValid, isClosed, hasSelfIntersection, errors: string[] }.
 */
export function validatePolygon(points) {
    const errors = [];
    const isClosed = Array.isArray(points) && points.length >= 3;
    if (!isClosed) {
        errors.push('Boundary needs at least 3 points to form a closed shape.');
    }
    let hasSelfIntersection = false;
    if (isClosed) {
        hasSelfIntersection = isPolygonSelfIntersecting(points);
        if (hasSelfIntersection) {
            errors.push('Boundary lines cross each other — the shape is self-intersecting.');
        }
    }
    const area = isClosed ? calculatePolygonArea(points) : 0;
    if (isClosed && area < 1e-6) {
        errors.push('The selected points are collinear or overlapping and enclose no area.');
    }
    return {
        isValid: errors.length === 0,
        isClosed,
        hasSelfIntersection,
        area,
        errors
    };
}
