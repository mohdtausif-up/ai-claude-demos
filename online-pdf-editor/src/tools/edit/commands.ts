/** Undoable annotation operations — the edit-tool analog of tools/organize/commands.ts. */
import type { AnnotationObject } from '../../lib/annotations';
import type { DocState } from '../../lib/pageModel';
import { makeCommand, type Command } from '../../store/history';

function updatePage(state: DocState, pageId: string, fn: (annotations: AnnotationObject[]) => AnnotationObject[]): DocState {
  return {
    ...state,
    pages: state.pages.map((p) => (p.id === pageId ? { ...p, annotations: fn(p.annotations) } : p)),
  };
}

export function addAnnotation(pageId: string, annotation: AnnotationObject, label: string): Command {
  return makeCommand(label, (state) => updatePage(state, pageId, (list) => [...list, annotation]));
}

/** One command per finished gesture (drag/resize/type) — not per intermediate frame. */
export function updateAnnotation(pageId: string, id: string, patch: Partial<AnnotationObject>, label = 'Edit annotation'): Command {
  return makeCommand(label, (state) =>
    updatePage(state, pageId, (list) => list.map((a) => (a.id === id ? ({ ...a, ...patch } as AnnotationObject) : a))),
  );
}

export function removeAnnotation(pageId: string, id: string, label = 'Delete annotation'): Command {
  return makeCommand(label, (state) => updatePage(state, pageId, (list) => list.filter((a) => a.id !== id)));
}

export function removeAnnotations(pageId: string, ids: string[], label = 'Delete annotations'): Command {
  const set = new Set(ids);
  return makeCommand(label, (state) => updatePage(state, pageId, (list) => list.filter((a) => !set.has(a.id))));
}
