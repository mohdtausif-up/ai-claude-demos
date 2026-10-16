import { beforeEach, describe, expect, it } from 'vitest';
import { newAnnotationId, type AnnotationObject } from '../../lib/annotations';
import { DEFAULT_DESIGN_SETTINGS } from '../../lib/design';
import { blankPageRef, type DocState } from '../../lib/pageModel';
import { useDocumentStore } from '../../store/documentStore';
import { addAnnotation, removeAnnotation, removeAnnotations, updateAnnotation } from './commands';

const store = () => useDocumentStore.getState();
const rect: AnnotationObject = {
  id: newAnnotationId(),
  type: 'rectangle',
  rect: { left: 0, top: 0, width: 0.5, height: 0.5 },
  fillColor: '#ff0000',
  strokeColor: null,
  strokeWidth: 0,
  opacity: 1,
};

beforeEach(() => {
  const page = blankPageRef({ width: 100, height: 100 });
  const doc: DocState = { sources: {}, pages: [page], design: DEFAULT_DESIGN_SETTINGS, formValues: {}, flattenForms: false };
  store().open(doc, 'test.pdf');
});

describe('addAnnotation', () => {
  it('appends to the page and undoes cleanly', () => {
    const pageId = store().doc!.pages[0].id;
    store().run(addAnnotation(pageId, rect, 'Add rectangle'));
    expect(store().doc!.pages[0].annotations).toEqual([rect]);
    store().undo();
    expect(store().doc!.pages[0].annotations).toEqual([]);
    store().redo();
    expect(store().doc!.pages[0].annotations).toEqual([rect]);
  });

  it('does not affect other pages', () => {
    const page2 = blankPageRef({ width: 100, height: 100 });
    store().open({ sources: {}, pages: [store().doc!.pages[0], page2], design: DEFAULT_DESIGN_SETTINGS, formValues: {}, flattenForms: false }, 'test.pdf');
    store().run(addAnnotation(page2.id, rect, 'Add rectangle'));
    expect(store().doc!.pages[0].annotations).toEqual([]);
    expect(store().doc!.pages[1].annotations).toEqual([rect]);
  });
});

describe('updateAnnotation', () => {
  it('patches only the matching annotation and undoes to the exact prior value', () => {
    const pageId = store().doc!.pages[0].id;
    store().run(addAnnotation(pageId, rect, 'Add rectangle'));
    store().run(updateAnnotation(pageId, rect.id, { opacity: 0.5 }));
    expect(store().doc!.pages[0].annotations[0]).toMatchObject({ opacity: 0.5, id: rect.id });
    store().undo();
    expect(store().doc!.pages[0].annotations[0]).toEqual(rect);
  });
});

describe('removeAnnotation / removeAnnotations', () => {
  it('removes one annotation by id', () => {
    const pageId = store().doc!.pages[0].id;
    const rect2 = { ...rect, id: newAnnotationId() };
    store().run(addAnnotation(pageId, rect, 'a'));
    store().run(addAnnotation(pageId, rect2, 'b'));
    store().run(removeAnnotation(pageId, rect.id));
    expect(store().doc!.pages[0].annotations).toEqual([rect2]);
  });

  it('removes several annotations at once, undoably', () => {
    const pageId = store().doc!.pages[0].id;
    const rect2 = { ...rect, id: newAnnotationId() };
    store().run(addAnnotation(pageId, rect, 'a'));
    store().run(addAnnotation(pageId, rect2, 'b'));
    store().run(removeAnnotations(pageId, [rect.id, rect2.id]));
    expect(store().doc!.pages[0].annotations).toEqual([]);
    store().undo();
    expect(store().doc!.pages[0].annotations).toEqual([rect, rect2]);
  });
});
