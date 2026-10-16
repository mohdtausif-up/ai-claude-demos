import { describe, expect, it } from 'vitest';
import {
  canvasBoxToContentRect,
  canvasPointToContent,
  contentPointToCanvas,
  contentRectToCanvasBox,
} from '../annotateGeometry';
import type { Rotation } from '../pageModel';

const canvas = { width: 400, height: 200 };

describe('contentRectToCanvasBox / canvasBoxToContentRect', () => {
  it('maps a content rect to pixels with no rotation', () => {
    const rect = { left: 0.25, top: 0.5, width: 0.5, height: 0.25 };
    expect(contentRectToCanvasBox(rect, 0, canvas)).toEqual({ left: 100, top: 100, width: 200, height: 50 });
  });

  it.each([0, 90, 180, 270] as Rotation[])('round-trips through canvas pixels at %i°', (rotation) => {
    const rect = { left: 0.1, top: 0.2, width: 0.3, height: 0.15 };
    const box = contentRectToCanvasBox(rect, rotation, canvas);
    const back = canvasBoxToContentRect(box, rotation, canvas);
    expect(back.left).toBeCloseTo(rect.left);
    expect(back.top).toBeCloseTo(rect.top);
    expect(back.width).toBeCloseTo(rect.width);
    expect(back.height).toBeCloseTo(rect.height);
  });

  it('a 90° rotation swaps which canvas axis a non-square rect scales against', () => {
    const rect = { left: 0, top: 0, width: 0.1, height: 0.2 };
    const box0 = contentRectToCanvasBox(rect, 0, canvas);
    const box90 = contentRectToCanvasBox(rect, 90, canvas);
    // Same fractional rect, but on this non-square (400x200) canvas, rotating
    // it swaps which axis its 0.1-wide/0.2-tall fractions get multiplied against.
    expect(box0.width).not.toBeCloseTo(box90.width);
  });
});

describe('contentPointToCanvas / canvasPointToContent', () => {
  it('maps a content point to pixels with no rotation', () => {
    expect(contentPointToCanvas({ x: 0.5, y: 0.5 }, 0, canvas)).toEqual({ x: 200, y: 100 });
  });

  it.each([0, 90, 180, 270] as Rotation[])('round-trips through canvas pixels at %i°', (rotation) => {
    const p = { x: 0.7, y: 0.2 };
    const back = canvasPointToContent(contentPointToCanvas(p, rotation, canvas), rotation, canvas);
    expect(back.x).toBeCloseTo(p.x);
    expect(back.y).toBeCloseTo(p.y);
  });
});
