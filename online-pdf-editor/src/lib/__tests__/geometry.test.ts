import { describe, expect, it } from 'vitest';
import {
  clampFracRect,
  displaySize,
  fitCentered,
  normalizeRotation,
  orientSize,
  rotateFracPoint,
  rotateFracRect,
  unrotateFracPoint,
  unrotateFracRect,
} from '../geometry';
import type { FracRect, PageRef } from '../pageModel';

const ref = (over: Partial<PageRef> = {}): PageRef => ({
  id: 'p',
  source: { kind: 'blank' },
  base: { width: 100, height: 200, rotation: 0, fields: [] },
  rotation: 0,
  crop: null,
  resize: null,
  annotations: [],
  ...over,
});

describe('normalizeRotation', () => {
  it.each([
    [0, 0], [90, 90], [360, 0], [-90, 270], [450, 90], [540, 180], [-270, 90],
  ])('%i → %i', (input, expected) => expect(normalizeRotation(input)).toBe(expected));
});

describe('rotateFracRect', () => {
  const r: FracRect = { left: 0.1, top: 0.2, width: 0.3, height: 0.4 };

  it('maps the top-left corner to the top-right on a 90° turn', () => {
    const corner = rotateFracRect({ left: 0, top: 0, width: 0.1, height: 0.2 }, 90);
    expect(corner.left).toBeCloseTo(0.8);
    expect(corner.top).toBeCloseTo(0);
    expect(corner.width).toBeCloseTo(0.2);
    expect(corner.height).toBeCloseTo(0.1);
  });

  it.each([0, 90, 180, 270] as const)('unrotate inverts rotate at %i°', (rot) => {
    const back = unrotateFracRect(rotateFracRect(r, rot), rot);
    expect(back.left).toBeCloseTo(r.left);
    expect(back.top).toBeCloseTo(r.top);
    expect(back.width).toBeCloseTo(r.width);
    expect(back.height).toBeCloseTo(r.height);
  });

  it('four quarter turns are the identity', () => {
    let x = r;
    for (let i = 0; i < 4; i++) x = rotateFracRect(x, 90);
    expect(x.left).toBeCloseTo(r.left);
    expect(x.top).toBeCloseTo(r.top);
  });
});

describe('rotateFracPoint', () => {
  it('agrees with rotateFracRect on a zero-size rect at the same point', () => {
    const p = { x: 0.2, y: 0.7 };
    for (const rot of [0, 90, 180, 270] as const) {
      const viaRect = rotateFracRect({ left: p.x, top: p.y, width: 0, height: 0 }, rot);
      const viaPoint = rotateFracPoint(p, rot);
      expect(viaPoint.x).toBeCloseTo(viaRect.left);
      expect(viaPoint.y).toBeCloseTo(viaRect.top);
    }
  });

  it.each([0, 90, 180, 270] as const)('unrotate inverts rotate at %i°', (rot) => {
    const p = { x: 0.3, y: 0.9 };
    const back = unrotateFracPoint(rotateFracPoint(p, rot), rot);
    expect(back.x).toBeCloseTo(p.x);
    expect(back.y).toBeCloseTo(p.y);
  });
});

describe('displaySize', () => {
  it('swaps width and height for quarter turns, counting intrinsic rotation', () => {
    expect(displaySize(ref())).toEqual({ width: 100, height: 200 });
    expect(displaySize(ref({ rotation: 90 }))).toEqual({ width: 200, height: 100 });
    expect(displaySize(ref({ base: { width: 100, height: 200, rotation: 90, fields: [] }, rotation: 90 }))).toEqual({
      width: 100,
      height: 200,
    });
  });

  it('applies crop and resize, and can ignore them for the crop tool', () => {
    const p = ref({ crop: { left: 0, top: 0, width: 0.5, height: 0.5 } });
    expect(displaySize(p)).toEqual({ width: 50, height: 100 });
    expect(displaySize({ ...p, resize: { width: 300, height: 400 } })).toEqual({ width: 300, height: 400 });
    expect(displaySize({ ...p, resize: { width: 300, height: 400 } }, { ignoreCrop: true })).toEqual({
      width: 100,
      height: 200,
    });
  });
});

describe('fitCentered', () => {
  it('fits a wide box into a square, centered vertically', () => {
    expect(fitCentered({ width: 200, height: 100 }, { width: 400, height: 400 })).toEqual({
      scale: 2,
      offsetX: 0,
      offsetY: 100,
    });
  });
});

describe('clampFracRect', () => {
  it('keeps rects inside the page with a minimum size', () => {
    expect(clampFracRect({ left: 0.9, top: -0.2, width: 0.5, height: 0 })).toEqual({
      left: 0.5,
      top: 0,
      width: 0.5,
      height: 0.01,
    });
  });
});

describe('orientSize', () => {
  const a4 = { width: 595, height: 842 };
  it('follows the current page orientation in auto mode', () => {
    expect(orientSize(a4, 'auto', { width: 800, height: 600 })).toEqual({ width: 842, height: 595 });
    expect(orientSize(a4, 'auto', { width: 600, height: 800 })).toEqual(a4);
  });
  it('can force an orientation', () => {
    expect(orientSize(a4, 'landscape', { width: 600, height: 800 })).toEqual({ width: 842, height: 595 });
  });
});
