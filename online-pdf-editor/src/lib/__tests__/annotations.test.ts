import { describe, expect, it } from 'vitest';
import { newAnnotationId, pagesNeedingTrueRedact, type AnnotationObject } from '../annotations';

const redact = (mode: 'visual' | 'true'): AnnotationObject => ({
  id: newAnnotationId(),
  type: 'redact',
  mode,
  rect: { left: 0, top: 0, width: 1, height: 1 },
  color: '#000000',
  opacity: 1,
});
const whiteout: AnnotationObject = {
  id: newAnnotationId(),
  type: 'whiteout',
  rect: { left: 0, top: 0, width: 1, height: 1 },
  color: '#ffffff',
  opacity: 1,
};

describe('pagesNeedingTrueRedact', () => {
  it('finds only pages with at least one true-mode redaction', () => {
    expect(pagesNeedingTrueRedact([[redact('visual')], [redact('true')], [whiteout], []])).toEqual([1]);
  });

  it('finds multiple pages, preserving index order', () => {
    expect(pagesNeedingTrueRedact([[redact('true')], [], [redact('true'), redact('visual')]])).toEqual([0, 2]);
  });

  it('returns an empty array when nothing needs true redaction', () => {
    expect(pagesNeedingTrueRedact([[redact('visual')], [whiteout], []])).toEqual([]);
  });
});
