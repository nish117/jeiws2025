/** Fixture geometry used by run-tests.js. All coordinates in pixels unless noted. */

// A perfect 100x50 rectangle -> 5000 sq units, perimeter 300.
export const RECTANGLE = [
    { x: 0, y: 0 },
    { x: 100, y: 0 },
    { x: 100, y: 50 },
    { x: 0, y: 50 }
];

// 3-4-5 right triangle -> area 6, perimeter 12.
export const TRIANGLE = [
    { x: 0, y: 0 },
    { x: 4, y: 0 },
    { x: 4, y: 3 }
];

// Irregular convex pentagon with a known shoelace area (computed by hand: 95).
export const IRREGULAR_PENTAGON = [
    { x: 0, y: 0 },
    { x: 10, y: 0 },
    { x: 12, y: 6 },
    { x: 5, y: 10 },
    { x: -2, y: 4 }
];

// Self-intersecting "bowtie" quadrilateral — must fail validation.
export const SELF_INTERSECTING = [
    { x: 0, y: 0 },
    { x: 10, y: 10 },
    { x: 10, y: 0 },
    { x: 0, y: 10 }
];

export const CALIBRATION_FIXTURES = {
    twoPoint: { p1: { x: 0, y: 0 }, p2: { x: 125, y: 0 }, realDistanceMeters: 25 } // 0.2 m/px
};
