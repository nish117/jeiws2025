/**
 * Undo/redo over the semantic app state (state.js), not over Fabric's own
 * canvas.toJSON(). Reasons that matters in practice for this app:
 *   - toJSON() re-serializes the (often multi-megabyte, base64-embedded)
 *     background image on every single snapshot — untenable for a 50-step
 *     undo stack.
 *   - It carries Fabric-internal rendering properties (fill/stroke/
 *     selection controls) that have nothing to do with our geometry.
 *   - It still wouldn't contain our derived measurements — those always
 *     have to be recomputed from state via geometry.js regardless, which
 *     makes a canvas-level history redundant on top of being too heavy.
 *
 * Usage: call history.commit() BEFORE a discrete mutation (so the stack
 * holds the pre-mutation state), then mutate via store.setState(). For
 * continuous drags, commit once on release (Fabric's "object:modified"),
 * never on every "object:moving" frame.
 */
export class History {
    constructor(store, maxSize = 50) {
        this.store = store;
        this.maxSize = maxSize;
        this.undoStack = [];
        this.redoStack = [];
    }

    commit() {
        this.undoStack.push(this.store.snapshot());
        if (this.undoStack.length > this.maxSize) this.undoStack.shift();
        this.redoStack = [];
    }

    undo() {
        if (!this.canUndo()) return false;
        this.redoStack.push(this.store.snapshot());
        const previous = this.undoStack.pop();
        this.store.restore(previous);
        return true;
    }

    redo() {
        if (!this.canRedo()) return false;
        this.undoStack.push(this.store.snapshot());
        const next = this.redoStack.pop();
        this.store.restore(next);
        return true;
    }

    canUndo() {
        return this.undoStack.length > 0;
    }

    canRedo() {
        return this.redoStack.length > 0;
    }

    clear() {
        this.undoStack = [];
        this.redoStack = [];
    }
}
