/**
 * Coordinate conversion between the annotation model's content-space
 * fractions (relative to the page's original, unrotated box — see
 * annotations.ts) and on-screen canvas pixels, given the page's current
 * display rotation and canvas size. Kept separate from AnnotateOverlay.tsx so
 * this math is unit-testable without a browser/Fabric.
 */
import { rotateFracPoint, rotateFracRect, unrotateFracPoint, unrotateFracRect } from './geometry';
import type { FracRect, Rotation, Size } from './pageModel';
import type { Point } from './annotations';

export interface PixelBox {
  left: number;
  top: number;
  width: number;
  height: number;
}

export function contentRectToCanvasBox(rect: FracRect, rotation: Rotation, canvas: Size): PixelBox {
  const d = rotateFracRect(rect, rotation);
  return { left: d.left * canvas.width, top: d.top * canvas.height, width: d.width * canvas.width, height: d.height * canvas.height };
}

export function canvasBoxToContentRect(box: PixelBox, rotation: Rotation, canvas: Size): FracRect {
  const d: FracRect = {
    left: box.left / canvas.width,
    top: box.top / canvas.height,
    width: box.width / canvas.width,
    height: box.height / canvas.height,
  };
  return unrotateFracRect(d, rotation);
}

export function contentPointToCanvas(point: Point, rotation: Rotation, canvas: Size): Point {
  const d = rotateFracPoint(point, rotation);
  return { x: d.x * canvas.width, y: d.y * canvas.height };
}

export function canvasPointToContent(point: Point, rotation: Rotation, canvas: Size): Point {
  const d: Point = { x: point.x / canvas.width, y: point.y / canvas.height };
  return unrotateFracPoint(d, rotation);
}
