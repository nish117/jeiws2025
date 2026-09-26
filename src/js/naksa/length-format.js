/**
 * Formats a real-world length (in metres) in whichever unit the user has
 * picked via the "Show lengths in" toggle — shared by plot-tools.js (the
 * live edge-length labels while drawing a plot) and measure-tool.js (the
 * standalone distance ruler), so switching units affects both consistently.
 */
export const METERS_TO_FEET = 3.280839895; // 1 / 0.3048, exact

export function formatFeetInches(meters) {
    const totalInches = meters * METERS_TO_FEET * 12;
    let feet = Math.floor(totalInches / 12);
    let inches = Math.round((totalInches - feet * 12) * 10) / 10;
    if (inches >= 12) { feet += 1; inches -= 12; } // rounding can carry the last inch into the next foot
    return `${feet}' ${inches}"`;
}

export function formatLengthInUnit(meters, unit) {
    switch (unit) {
        case 'ft-in': return formatFeetInches(meters);
        case 'ft': return `${(meters * METERS_TO_FEET).toFixed(2)} ft`;
        case 'cm': return `${(meters * 100).toFixed(1)} cm`;
        case 'm':
        default: return `${meters.toFixed(2)} m`;
    }
}
