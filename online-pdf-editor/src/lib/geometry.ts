import type { Point } from './annotations';
import type { FracRect, PageRef, Rotation, Size } from './pageModel';

export const FULL_RECT: FracRect = { left: 0, top: 0, width: 1, height: 1 };

/** A page (or crop) box in PDF's native bottom-left-origin coordinate system. */
export interface PdfBox {
  x: number;
  y: number;
  width: number;
  height: number;
}

/**
 * Converts a top-left-based fractional rect (the app's convention throughout —
 * crop, annotations) into an absolute PDF-space rect (bottom-left origin)
 * within the given box. Shared by the crop tool and annotation flattening so
 * both agree on the same math.
 */
export function fracRectToPdfBox(rect: FracRect, box: PdfBox): PdfBox {
  return {
    x: box.x + rect.left * box.width,
    y: box.y + (1 - rect.top - rect.height) * box.height,
    width: rect.width * box.width,
    height: rect.height * box.height,
  };
}

/**
 * Inverse of fracRectToPdfBox: an absolute PDF-space rect within `box` → a
 * top-left-based fractional rect. Used to import existing AcroForm widget
 * positions (which are stored in absolute PDF space) into the app's
 * fractional convention, the same way annotations and crop already work.
 */
export function pdfBoxToFracRect(rect: PdfBox, box: PdfBox): FracRect {
  const width = rect.width / box.width;
  const height = rect.height / box.height;
  return { left: (rect.x - box.x) / box.width, top: 1 - height - (rect.y - box.y) / box.height, width, height };
}

/** Converts a single top-left-based fractional point into absolute PDF space. */
export function fracPointToPdf(p: Point, box: PdfBox): Point {
  return { x: box.x + p.x * box.width, y: box.y + (1 - p.y) * box.height };
}

export function normalizeRotation(degrees: number): Rotation {
  const quarter = Math.round(degrees / 90);
  return ((((quarter % 4) + 4) % 4) * 90) as Rotation;
}

/** Rotation the page is displayed with: its own /Rotate plus the user's rotation. */
export function totalRotation(ref: PageRef): Rotation {
  return normalizeRotation(ref.base.rotation + ref.rotation);
}

export function isQuarterTurn(rotation: Rotation): boolean {
  return rotation === 90 || rotation === 270;
}

export function swapIf(size: Size, swap: boolean): Size {
  return swap ? { width: size.height, height: size.width } : size;
}

/**
 * Maps a rect on an unrotated page to the same region on the page rotated
 * clockwise by `rotation` (PDF /Rotate is clockwise).
 */
export function rotateFracRect(r: FracRect, rotation: Rotation): FracRect {
  switch (rotation) {
    case 0:
      return { ...r };
    case 90:
      return { left: 1 - r.top - r.height, top: r.left, width: r.height, height: r.width };
    case 180:
      return { left: 1 - r.left - r.width, top: 1 - r.top - r.height, width: r.width, height: r.height };
    case 270:
      return { left: r.top, top: 1 - r.left - r.width, width: r.height, height: r.width };
  }
}

/** Inverse of rotateFracRect: display-space rect → unrotated page-space rect. */
export function unrotateFracRect(r: FracRect, rotation: Rotation): FracRect {
  return rotateFracRect(r, normalizeRotation(360 - rotation));
}

/** Same mapping as rotateFracRect, for a single fractional point (line/arrow/draw endpoints). */
export function rotateFracPoint(p: Point, rotation: Rotation): Point {
  switch (rotation) {
    case 0:
      return { ...p };
    case 90:
      return { x: 1 - p.y, y: p.x };
    case 180:
      return { x: 1 - p.x, y: 1 - p.y };
    case 270:
      return { x: p.y, y: 1 - p.x };
  }
}

export function unrotateFracPoint(p: Point, rotation: Rotation): Point {
  return rotateFracPoint(p, normalizeRotation(360 - rotation));
}

export function clampFracRect(r: FracRect, minSize = 0.01): FracRect {
  const width = Math.min(Math.max(r.width, minSize), 1);
  const height = Math.min(Math.max(r.height, minSize), 1);
  const left = Math.min(Math.max(r.left, 0), 1 - width);
  const top = Math.min(Math.max(r.top, 0), 1 - height);
  return { left, top, width, height };
}

export function isFullRect(r: FracRect, epsilon = 1e-4): boolean {
  return (
    Math.abs(r.left) < epsilon &&
    Math.abs(r.top) < epsilon &&
    Math.abs(r.width - 1) < epsilon &&
    Math.abs(r.height - 1) < epsilon
  );
}

/** Unrotated size after crop, before resize. */
export function croppedSize(ref: PageRef): Size {
  const crop = ref.crop ?? FULL_RECT;
  return { width: ref.base.width * crop.width, height: ref.base.height * crop.height };
}

/** Final unrotated page size in points. */
export function unrotatedSize(ref: PageRef): Size {
  return ref.resize ?? croppedSize(ref);
}

/** Final page size as the user sees it (after rotation), in points. */
export function displaySize(ref: PageRef, opts: { ignoreCrop?: boolean; ignoreResize?: boolean } = {}): Size {
  const r = { ...ref, crop: opts.ignoreCrop ? null : ref.crop, resize: opts.ignoreResize || opts.ignoreCrop ? null : ref.resize };
  return swapIf(unrotatedSize(r), isQuarterTurn(totalRotation(ref)));
}

/**
 * Scale + offset that fits a `content` box into a `target` box, centered,
 * preserving aspect ratio. Shared by the preview renderer and the PDF writer so
 * the two always agree.
 */
export function fitCentered(content: Size, target: Size): { scale: number; offsetX: number; offsetY: number } {
  const scale = Math.min(target.width / content.width, target.height / content.height);
  return {
    scale,
    offsetX: (target.width - content.width * scale) / 2,
    offsetY: (target.height - content.height * scale) / 2,
  };
}

/** Orient a paper size to match (or be forced to) a page's orientation. */
export function orientSize(paper: Size, orientation: 'auto' | 'portrait' | 'landscape', current: Size): Size {
  const wantLandscape =
    orientation === 'landscape' || (orientation === 'auto' && current.width > current.height);
  const isLandscape = paper.width > paper.height;
  return wantLandscape === isLandscape ? paper : { width: paper.height, height: paper.width };
}
