import type { DocState } from '../lib/pageModel';

/** An undoable edit to the document. */
export interface Command {
  label: string;
  execute(state: DocState): DocState;
  undo(state: DocState): DocState;
}

/**
 * Builds a Command from a pure transform. The state it replaced (and produced)
 * is remembered, so undo/redo are exact even if the transform generates new ids.
 * DocState is immutable and structurally shared, so this costs only the
 * changed page-list array, never the PDF bytes.
 */
export function makeCommand(label: string, transform: (state: DocState) => DocState): Command {
  let before: DocState | null = null;
  let after: DocState | null = null;
  return {
    label,
    execute(state) {
      if (after && before === state) return after;
      before = state;
      after = transform(state);
      return after;
    },
    undo(state) {
      if (!before || state !== after) throw new Error(`Cannot undo "${label}": history is out of sync.`);
      return before;
    },
  };
}

export const HISTORY_LIMIT = 200;
