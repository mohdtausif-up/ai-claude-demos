import { create } from 'zustand';
import type { DocState } from '../lib/pageModel';
import { HISTORY_LIMIT, type Command } from './history';

interface DocumentStore {
  doc: DocState | null;
  /** Name used for downloads (the first file opened). */
  fileName: string;
  past: Command[];
  future: Command[];
  open(doc: DocState, fileName: string): void;
  close(): void;
  /** Executes and records a command. Throws (leaving state unchanged) if the command rejects. */
  run(command: Command): void;
  undo(): void;
  redo(): void;
}

export const useDocumentStore = create<DocumentStore>((set, get) => ({
  doc: null,
  fileName: '',
  past: [],
  future: [],

  open: (doc, fileName) => set({ doc, fileName, past: [], future: [] }),
  close: () => set({ doc: null, fileName: '', past: [], future: [] }),

  run: (command) => {
    const { doc, past } = get();
    if (!doc) return;
    const next = command.execute(doc);
    set({ doc: next, past: [...past, command].slice(-HISTORY_LIMIT), future: [] });
  },

  undo: () => {
    const { doc, past, future } = get();
    const command = past.at(-1);
    if (!doc || !command) return;
    set({ doc: command.undo(doc), past: past.slice(0, -1), future: [command, ...future] });
  },

  redo: () => {
    const { doc, past, future } = get();
    const command = future[0];
    if (!doc || !command) return;
    set({ doc: command.execute(doc), past: [...past, command], future: future.slice(1) });
  },
}));
