import { test, equal, closeTo, isTrue, isFalse, throws, renderResults, results } from './assert.js';
import * as G from '../geometry.js';
import * as U from '../units.js';
import * as C from '../calibration.js';
import { RECTANGLE, TRIANGLE, IRREGULAR_PENTAGON, SELF_INTERSECTING, CALIBRATION_FIXTURES } from './sample-data.js';

// ── Geometry ────────────────────────────────────────────────────────────
test('rectangle area via shoelace', () => {
    closeTo(G.calculatePolygonArea(RECTANGLE), 5000, 1e-6);
});
test('rectangle perimeter', () => {
    closeTo(G.calculatePerimeter(RECTANGLE), 300, 1e-6);
});
test('triangle (3-4-5) area', () => {
    closeTo(G.calculatePolygonArea(TRIANGLE), 6, 1e-6);
});
test('triangle (3-4-5) perimeter', () => {
    closeTo(G.calculatePerimeter(TRIANGLE), 12, 1e-6);
});
test('irregular pentagon area matches hand calculation', () => {
    closeTo(G.calculatePolygonArea(IRREGULAR_PENTAGON), 95, 1e-6);
});
test('rectangle side lengths are 100,50,100,50', () => {
    const sides = G.calculateSideLengths(RECTANGLE);
    equal(sides.length, 4);
    closeTo(sides[0].length, 100, 1e-6);
    closeTo(sides[1].length, 50, 1e-6);
    closeTo(sides[2].length, 100, 1e-6);
    closeTo(sides[3].length, 50, 1e-6);
});
test('rectangle centroid is at its center', () => {
    const c = G.calculateCentroid(RECTANGLE);
    closeTo(c.x, 50, 1e-6);
    closeTo(c.y, 25, 1e-6);
});
test('bounding box matches rectangle extents', () => {
    const bb = G.calculateBoundingBox(RECTANGLE);
    equal(bb.width, 100);
    equal(bb.height, 50);
});
test('valid closed rectangle passes validation', () => {
    const r = G.validatePolygon(RECTANGLE);
    isTrue(r.isValid);
    isFalse(r.hasSelfIntersection);
});
test('self-intersecting bowtie fails validation', () => {
    const r = G.validatePolygon(SELF_INTERSECTING);
    isFalse(r.isValid);
    isTrue(r.hasSelfIntersection);
});
test('fewer than 3 points fails validation', () => {
    const r = G.validatePolygon([{ x: 0, y: 0 }, { x: 1, y: 1 }]);
    isFalse(r.isValid);
});

// ── Units ───────────────────────────────────────────────────────────────
test('1 Ropani in sq ft converts back to exactly 1 Ropani 0 Aana 0 Paisa 0 Daam', () => {
    const result = U.convertSqFtToRopaniSystem(U.HILL_UNITS_SQFT.ropani);
    equal(result.ropani, 1);
    equal(result.aana, 0);
    equal(result.paisa, 0);
    equal(result.daam, 0);
});
test('1 Bigha in sq ft converts back to exactly 1 Bigha 0 Kattha 0 Dhur', () => {
    const result = U.convertSqFtToBighaSystem(U.TERAI_UNITS_SQFT.bigha);
    equal(result.bigha, 1);
    equal(result.kattha, 0);
    equal(result.dhur, 0);
});
test('4375 sq ft converts to a sane Ropani breakdown without cascade drift', () => {
    const result = U.convertSqFtToRopaniSystem(4375);
    // 4375 / 342.25 = 12.78... aana -> 0 ropani, 12 aana, remainder
    equal(result.ropani, 0);
    equal(result.aana, 12);
});
test('sq ft <-> sq m round-trips exactly via the exact factor', () => {
    const sqm = 508.72; // arbitrary value
    const roundTrip = sqm * U.SQFT_TO_SQM * U.SQM_TO_SQFT;
    closeTo(roundTrip, sqm, 1e-9);
});
test('convertAreaToNepalUnits returns all unit families simultaneously', () => {
    const out = U.convertAreaToNepalUnits(508.72); // ~1 ropani
    isTrue(out.sqft > 0);
    isTrue(out.sqm > 0);
    isTrue(out.sqyd > 0);
    isTrue('ropani' in out.ropaniSystem);
    isTrue('bigha' in out.bighaSystem);
});

// ── Calibration ─────────────────────────────────────────────────────────
test('two-point calibration computes expected metres/pixel', () => {
    const f = CALIBRATION_FIXTURES.twoPoint;
    const result = C.calibrateFromTwoPoints(f.p1, f.p2, f.realDistanceMeters);
    closeTo(result.metersPerPixel, 0.2, 1e-9);
});
test('calibrating with identical points throws', () => {
    throws(() => C.calibrateFromTwoPoints({ x: 5, y: 5 }, { x: 5, y: 5 }, 10));
});
test('calibration points closer than 20px triggers a low-confidence warning', () => {
    const result = C.calibrateFromTwoPoints({ x: 0, y: 0 }, { x: 5, y: 0 }, 1);
    const warnings = C.getCalibrationWarnings(result);
    isTrue(warnings.length > 0);
});
test('real-world polygon area uses calibrated points, not raw pixels', () => {
    // 100x50 px rectangle at 0.2 m/px -> 20x10 m rectangle -> 200 sq m.
    const real = C.convertPointsToRealWorld(RECTANGLE, 0.2);
    closeTo(G.calculatePolygonArea(real), 200, 1e-6);
});

export function runAll(containerEl) {
    renderResults(containerEl);
    return results;
}
