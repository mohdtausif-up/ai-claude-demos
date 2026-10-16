import { beforeEach, describe, expect, it } from 'vitest';
import { DEFAULT_DESIGN_SETTINGS } from '../../lib/design';
import { blankPageRef, type DocState } from '../../lib/pageModel';
import { useDocumentStore } from '../../store/documentStore';
import { deletePages, insertPages, movePages, rotatePages, setCrops, setResizes } from './commands';

const page = (id: string) => ({ ...blankPageRef({ width: 100, height: 100 }), id });
const ids = () => useDocumentStore.getState().doc!.pages.map((p) => p.id);
const store = () => useDocumentStore.getState();

beforeEach(() => {
  const doc: DocState = { sources: {}, pages: ['a', 'b', 'c', 'd'].map(page), design: DEFAULT_DESIGN_SETTINGS, formValues: {}, flattenForms: false };
  store().open(doc, 'test.pdf');
});

describe('movePages', () => {
  it('moves a page before another', () => {
    store().run(movePages(['d'], 'b'));
    expect(ids()).toEqual(['a', 'd', 'b', 'c']);
  });

  it('moves several pages to the end, keeping their order', () => {
    store().run(movePages(['c', 'a'], null));
    expect(ids()).toEqual(['b', 'd', 'a', 'c']);
  });

  it('handles dropping onto one of the moved pages', () => {
    store().run(movePages(['b', 'c'], 'c'));
    expect(ids()).toEqual(['a', 'b', 'c', 'd']);
  });
});

describe('deletePages', () => {
  it('deletes and undoes exactly', () => {
    store().run(deletePages(['b', 'c']));
    expect(ids()).toEqual(['a', 'd']);
    store().undo();
    expect(ids()).toEqual(['a', 'b', 'c', 'd']);
    store().redo();
    expect(ids()).toEqual(['a', 'd']);
  });

  it('refuses to delete every page and leaves history untouched', () => {
    expect(() => store().run(deletePages(['a', 'b', 'c', 'd']))).toThrow(/at least one page/);
    expect(ids()).toHaveLength(4);
    expect(store().past).toHaveLength(0);
  });
});

describe('rotatePages', () => {
  it('rotates, wraps around, and undoes', () => {
    store().run(rotatePages(['a'], -90));
    expect(store().doc!.pages[0].rotation).toBe(270);
    store().run(rotatePages(['a'], 180));
    expect(store().doc!.pages[0].rotation).toBe(90);
    store().undo();
    store().undo();
    expect(store().doc!.pages[0].rotation).toBe(0);
  });
});

describe('insertPages', () => {
  it('inserts at an index and registers new sources', () => {
    const source = { id: 's', name: 's.pdf', bytes: new Uint8Array(), pages: [] };
    store().run(insertPages([page('x')], 1, [source]));
    expect(ids()).toEqual(['a', 'x', 'b', 'c', 'd']);
    expect(store().doc!.sources.s).toBe(source);
    store().undo();
    expect(store().doc!.sources.s).toBeUndefined();
  });

  it('keeps the same ids on redo', () => {
    store().run(insertPages([blankPageRef({ width: 1, height: 1 })], 0));
    const first = ids()[0];
    store().undo();
    store().redo();
    expect(ids()[0]).toBe(first);
  });
});

describe('setCrops / setResizes', () => {
  it('sets and clears per page', () => {
    const crop = { left: 0.1, top: 0.1, width: 0.5, height: 0.5 };
    store().run(setCrops({ a: crop, b: crop }));
    expect(store().doc!.pages.map((p) => p.crop)).toEqual([crop, crop, null, null]);
    store().run(setResizes({ c: { width: 10, height: 20 } }));
    expect(store().doc!.pages[2].resize).toEqual({ width: 10, height: 20 });
    expect(store().past.map((c) => c.label)).toEqual(['Crop 2 pages', 'Resize 1 page']);
  });
});

describe('history', () => {
  it('clears redo stack after a new command', () => {
    store().run(rotatePages(['a'], 90));
    store().undo();
    expect(store().future).toHaveLength(1);
    store().run(rotatePages(['b'], 90));
    expect(store().future).toHaveLength(0);
  });
});
