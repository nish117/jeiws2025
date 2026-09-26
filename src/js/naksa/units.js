/**
 * Nepal land-area unit conversion.
 *
 * Every unit is defined ONCE, in square feet — the unit Nepal's own survey
 * (Naapi) records and legal land documents are traditionally expressed in —
 * and every other unit (sq m, sq yd, and the mixed-unit breakdowns) is
 * derived from that single base at runtime via exact factors. This
 * deliberately avoids a real bug found in this repo's existing
 * src/js/functions.js, which hardcodes the sq-m equivalent of 1 Ropani as
 * 508.72 in one function and 508.74 in another (same for the Bigha figures:
 * 6772.63 vs 6772.41) — two independently hand-rounded copies of what
 * should be one constant, which drift apart. Deriving everything from one
 * sq-ft base makes that class of bug structurally impossible here.
 */

// 1 ft = 0.3048 m exactly, so 1 sq ft = 0.3048^2 sq m exactly.
export const SQFT_TO_SQM = 0.09290304;
export const SQM_TO_SQFT = 1 / SQFT_TO_SQM;
export const SQFT_TO_SQYD = 1 / 9; // 1 sq yd = 9 sq ft, exact.
export const SQYD_TO_SQFT = 9;

/**
 * Hill/Kathmandu-Valley system. 1 Ropani = 16 Aana = 64 Paisa = 256 Daam.
 * Base figure: 1 Ropani = 5476 sq ft (the standard, universally-cited value).
 */
export const HILL_UNITS_SQFT = {
    ropani: 5476,
    aana: 5476 / 16,       // 342.25
    paisa: 5476 / 64,      // 85.5625
    daam: 5476 / 256       // 21.390625
};

/**
 * Terai system. 1 Bigha = 20 Kattha = 400 Dhur.
 * Base figure: 1 Bigha = 72900 sq ft (equivalently, 1 Dhur = 182.25 sq ft).
 */
export const TERAI_UNITS_SQFT = {
    bigha: 72900,
    kattha: 72900 / 20,    // 3645
    dhur: 72900 / 400      // 182.25
};

function roundTo(value, decimals) {
    const f = Math.pow(10, decimals);
    return Math.round(value * f) / f;
}

/**
 * Largest-unit-first floor/mod cascade (e.g. "1 Ropani 2 Aana 1 Paisa").
 * The remainder is rounded to a fixed precision after every step —
 * without this, JS floating point on non-integer divisors like 342.25 can
 * leave a residue of 2.9999999998 instead of 3, which then floors the
 * *next* unit down by one. `unitOrder` is [{name, sqft}, ...] largest first;
 * the last entry is reported as a decimal rather than floored.
 */
function cascade(totalSqFt, unitOrder) {
    const result = {};
    let remainder = roundTo(Math.max(totalSqFt, 0), 6);
    for (let i = 0; i < unitOrder.length; i++) {
        const { name, sqft } = unitOrder[i];
        const isLast = i === unitOrder.length - 1;
        if (isLast) {
            result[name] = roundTo(remainder / sqft, 3);
        } else {
            const count = Math.floor(roundTo(remainder / sqft, 6));
            result[name] = count;
            remainder = roundTo(remainder - count * sqft, 6);
        }
    }
    return result;
}

export function convertSqFtToRopaniSystem(areaSqFt) {
    return cascade(areaSqFt, [
        { name: 'ropani', sqft: HILL_UNITS_SQFT.ropani },
        { name: 'aana', sqft: HILL_UNITS_SQFT.aana },
        { name: 'paisa', sqft: HILL_UNITS_SQFT.paisa },
        { name: 'daam', sqft: HILL_UNITS_SQFT.daam }
    ]);
}

export function convertSqFtToBighaSystem(areaSqFt) {
    return cascade(areaSqFt, [
        { name: 'bigha', sqft: TERAI_UNITS_SQFT.bigha },
        { name: 'kattha', sqft: TERAI_UNITS_SQFT.kattha },
        { name: 'dhur', sqft: TERAI_UNITS_SQFT.dhur }
    ]);
}

export function formatRopaniSystem({ ropani, aana, paisa, daam }) {
    const parts = [];
    if (ropani) parts.push(`${ropani} Ropani`);
    if (aana) parts.push(`${aana} Aana`);
    if (paisa) parts.push(`${paisa} Paisa`);
    if (daam || parts.length === 0) parts.push(`${daam} Daam`);
    return parts.join(' ');
}

export function formatBighaSystem({ bigha, kattha, dhur }) {
    const parts = [];
    if (bigha) parts.push(`${bigha} Bigha`);
    if (kattha) parts.push(`${kattha} Kattha`);
    if (dhur || parts.length === 0) parts.push(`${dhur} Dhur`);
    return parts.join(' ');
}

/**
 * Master conversion: pass a real-world area in square metres (the natural
 * unit once calibration has been applied), get back every representation
 * the tool displays simultaneously. Nothing here picks a "primary" unit —
 * the UI shows all of them at once per the spec, with hill vs. terai framed
 * as two different regional systems rather than one tool "guessing" which
 * applies to a given plot.
 */
export function convertAreaToNepalUnits(areaSqM) {
    const areaSqFt = areaSqM * SQM_TO_SQFT;
    const areaSqYd = areaSqFt * SQFT_TO_SQYD;
    return {
        sqm: roundTo(areaSqM, 3),
        sqft: roundTo(areaSqFt, 3),
        sqyd: roundTo(areaSqYd, 3),
        ropaniSystem: convertSqFtToRopaniSystem(areaSqFt),
        bighaSystem: convertSqFtToBighaSystem(areaSqFt)
    };
}
