/** Minimal hand-rolled assertion helpers for the no-npm test harness. */

export const results = [];

export function test(name, fn) {
    try {
        fn();
        results.push({ name, pass: true });
    } catch (err) {
        results.push({ name, pass: false, error: err.message || String(err) });
    }
}

export function equal(actual, expected, message) {
    if (actual !== expected) {
        throw new Error(`${message || 'Values differ'}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
    }
}

export function closeTo(actual, expected, eps, message) {
    if (Math.abs(actual - expected) > eps) {
        throw new Error(`${message || 'Values differ'}: expected ~${expected} (±${eps}), got ${actual}`);
    }
}

export function isTrue(value, message) {
    if (value !== true) throw new Error(message || `Expected true, got ${JSON.stringify(value)}`);
}

export function isFalse(value, message) {
    if (value !== false) throw new Error(message || `Expected false, got ${JSON.stringify(value)}`);
}

export function throws(fn, message) {
    try {
        fn();
    } catch (e) {
        return;
    }
    throw new Error(message || 'Expected function to throw, but it did not');
}

export function renderResults(containerEl) {
    const passed = results.filter(r => r.pass).length;
    const failed = results.length - passed;
    containerEl.innerHTML = `
        <div class="test-summary ${failed ? 'has-failures' : 'all-pass'}">
            ${passed}/${results.length} passed${failed ? `, ${failed} FAILED` : ''}
        </div>
        <ul class="test-list">
            ${results.map(r => `
                <li class="${r.pass ? 'pass' : 'fail'}">
                    <span class="test-icon">${r.pass ? '✓' : '✗'}</span>
                    <span class="test-name">${r.name}</span>
                    ${r.error ? `<div class="test-error">${r.error}</div>` : ''}
                </li>
            `).join('')}
        </ul>
    `;
}
