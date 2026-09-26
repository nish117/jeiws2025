/**
 * Central app state + a minimal pub/sub. Every panel (upload, calibration,
 * plot tools, results) reads/writes through this one object instead of
 * reaching into each other directly, which is what lets undo/redo
 * (history.js) and the results display work from one consistent source of
 * truth.
 *
 * Deliberately small: this tool does one thing (upload a naksa, calibrate
 * it, draw draggable-point plots, see their area). There is no
 * perspective/annotation/north/Kitta-info/export state because those
 * features don't exist here anymore.
 */

let idCounter = 0;
export function nextId(prefix) {
    idCounter += 1;
    return `${prefix}-${Date.now().toString(36)}-${idCounter}`;
}

export function createInitialState() {
    return {
        image: null, // { dataUrl, width, height, name }
        calibration: null, // see calibration.js return shape, or null if not yet calibrated
        // { id, name, points:[{x,y} in native image px], closed }
        plots: [],
        activePlotId: null
    };
}

export class Store {
    constructor(initialState) {
        this.state = initialState || createInitialState();
        this.listeners = new Set();
    }

    getState() {
        return this.state;
    }

    /** updater: either a partial object to shallow-merge, or a function (state) => newState. */
    setState(updater) {
        this.state = typeof updater === 'function'
            ? updater(this.state)
            : { ...this.state, ...updater };
        this._emit();
    }

    subscribe(fn) {
        this.listeners.add(fn);
        return () => this.listeners.delete(fn);
    }

    _emit() {
        this.listeners.forEach(fn => fn(this.state));
    }

    snapshot() {
        return JSON.parse(JSON.stringify(this.state));
    }

    restore(snapshot) {
        this.state = snapshot;
        this._emit();
    }
}

export function getActivePlot(state) {
    return (state.plots || []).find(p => p.id === state.activePlotId) || null;
}
