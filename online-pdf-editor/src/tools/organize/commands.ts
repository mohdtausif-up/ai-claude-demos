/** Undoable page operations. Pure: each returns a Command over DocState. */
import { normalizeRotation } from '../../lib/geometry';
import type { DocState, FracRect, PageRef, Rotation, Size, SourceDoc } from '../../lib/pageModel';
import { makeCommand, type Command } from '../../store/history';

const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;

function updatePages(state: DocState, ids: Iterable<string>, fn: (p: PageRef) => PageRef): DocState {
  const set = new Set(ids);
  return { ...state, pages: state.pages.map((p) => (set.has(p.id) ? fn(p) : p)) };
}

/** Moves pages (keeping their relative order) to just before `beforeId`, or to the end if null. */
export function movePages(ids: string[], beforeId: string | null): Command {
  return makeCommand(`Move ${plural(ids.length, 'page')}`, (state) => {
    const set = new Set(ids);
    const moving = state.pages.filter((p) => set.has(p.id));
    const rest = state.pages.filter((p) => !set.has(p.id));
    // If the drop target is itself being moved, drop before the next page that isn't.
    let target = beforeId;
    if (target && set.has(target)) {
      const from = state.pages.findIndex((p) => p.id === target);
      target = state.pages.slice(from).find((p) => !set.has(p.id))?.id ?? null;
    }
    const at = target ? rest.findIndex((p) => p.id === target) : rest.length;
    const index = at === -1 ? rest.length : at;
    return { ...state, pages: [...rest.slice(0, index), ...moving, ...rest.slice(index)] };
  });
}

export function deletePages(ids: string[]): Command {
  return makeCommand(`Delete ${plural(ids.length, 'page')}`, (state) => {
    const set = new Set(ids);
    const pages = state.pages.filter((p) => !set.has(p.id));
    if (pages.length === 0) throw new Error('A document needs at least one page.');
    return { ...state, pages };
  });
}

export function rotatePages(ids: string[], delta: number): Command {
  const dir = delta === 180 ? '180°' : delta > 0 ? 'right' : 'left';
  return makeCommand(`Rotate ${plural(ids.length, 'page')} ${dir}`, (state) =>
    updatePages(state, ids, (p) => ({ ...p, rotation: normalizeRotation(p.rotation + delta) as Rotation })),
  );
}

/** Inserts pages at `index`, registering any new source documents (merge/insert-from-file). */
export function insertPages(refs: PageRef[], index: number, newSources: SourceDoc[] = [], label?: string): Command {
  return makeCommand(label ?? `Insert ${plural(refs.length, 'page')}`, (state) => {
    const at = Math.max(0, Math.min(index, state.pages.length));
    const sources = { ...state.sources };
    for (const s of newSources) sources[s.id] = s;
    return { ...state, sources, pages: [...state.pages.slice(0, at), ...refs, ...state.pages.slice(at)] };
  });
}

/** Sets (or clears, with null) the crop of each listed page. */
export function setCrops(crops: Record<string, FracRect | null>): Command {
  const ids = Object.keys(crops);
  const cleared = ids.every((id) => crops[id] === null);
  return makeCommand(`${cleared ? 'Reset crop on' : 'Crop'} ${plural(ids.length, 'page')}`, (state) =>
    updatePages(state, ids, (p) => ({ ...p, crop: crops[p.id] })),
  );
}

/** Sets (or clears, with null) the target size of each listed page. */
export function setResizes(sizes: Record<string, Size | null>): Command {
  const ids = Object.keys(sizes);
  const cleared = ids.every((id) => sizes[id] === null);
  return makeCommand(`${cleared ? 'Reset size of' : 'Resize'} ${plural(ids.length, 'page')}`, (state) =>
    updatePages(state, ids, (p) => ({ ...p, resize: sizes[p.id] })),
  );
}
