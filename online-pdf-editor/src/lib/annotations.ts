/**
 * The annotation data model. Deliberately independent of Fabric.js (or any UI
 * library): Fabric is only ever used as an interactive *editing surface* that
 * reads/writes these plain objects, so the flatten-to-PDF pass (pdfBuild.ts)
 * and its unit tests never need a DOM or canvas.
 *
 * All positions are fractions of the page's *original* (unrotated,
 * pre-crop/resize) box — the same convention `PageRef.crop` uses — so an
 * annotation drawn while the page is rotated 90° is stored the same way it
 * would be if drawn unrotated, and rides along with the page's own
 * crop/rotate/resize transforms when the PDF is built.
 */
import type { FracRect } from './pageModel';

export type HexColor = string; // "#rrggbb"

export const STANDARD_FONT_FAMILIES = ['Helvetica', 'Times', 'Courier'] as const;
export type StandardFontFamily = (typeof STANDARD_FONT_FAMILIES)[number];

export interface FontStyle {
  family: StandardFontFamily;
  bold: boolean;
  italic: boolean;
}

export type TextAlign = 'left' | 'center' | 'right';

export interface Point {
  x: number;
  y: number;
}

interface BaseAnnotation {
  id: string;
  /** 0 = fully transparent, 1 = fully opaque. */
  opacity: number;
}

export interface TextAnnotation extends BaseAnnotation {
  type: 'text';
  rect: FracRect;
  text: string;
  font: FontStyle;
  /** In PDF points, at the page's original (un-resized) scale. */
  fontSize: number;
  color: HexColor;
  align: TextAlign;
}

export interface ShapeAnnotation extends BaseAnnotation {
  type: 'rectangle' | 'ellipse';
  rect: FracRect;
  fillColor: HexColor | null;
  strokeColor: HexColor | null;
  /** In PDF points. */
  strokeWidth: number;
}

export interface LineAnnotation extends BaseAnnotation {
  type: 'line' | 'arrow';
  start: Point;
  end: Point;
  strokeColor: HexColor;
  strokeWidth: number;
}

export interface PathAnnotation extends BaseAnnotation {
  type: 'draw';
  points: Point[];
  strokeColor: HexColor;
  strokeWidth: number;
}

/** Opaque box that hides content without altering it. Same visual as a redact box in 'visual' mode. */
export interface WhiteoutAnnotation extends BaseAnnotation {
  type: 'whiteout';
  rect: FracRect;
  color: HexColor;
}

/**
 * Two distinct modes, per the PRD's quality bar — never let one be mistaken
 * for the other in the UI:
 *  - 'visual': an opaque box, same as whiteout. Fast, but the underlying text
 *    is still in the file and can be recovered (copy-paste, text extraction).
 *  - 'true': the box is drawn the same way, but the page is then rasterized
 *    to an image and the original vector/text content discarded (see
 *    trueRedact.ts), so the hidden content is actually gone from the file.
 */
export type RedactMode = 'visual' | 'true';

export interface RedactAnnotation extends BaseAnnotation {
  type: 'redact';
  rect: FracRect;
  mode: RedactMode;
  color: HexColor;
}

export type MarkupKind = 'highlight' | 'underline' | 'strikethrough';

/** One or more line-fragments from a text selection (pdf.js text layer). */
export interface MarkupAnnotation extends BaseAnnotation {
  type: 'markup';
  kind: MarkupKind;
  rects: FracRect[];
  color: HexColor;
}

export interface LinkAnnotation extends BaseAnnotation {
  type: 'link';
  rect: FracRect;
  url: string;
}

export interface NoteAnnotation extends BaseAnnotation {
  type: 'note';
  /** Anchor position; the icon is drawn at a fixed small size regardless of page scale. */
  at: Point;
  text: string;
  color: HexColor;
}

/**
 * A visual signature image — drawn freehand, typed (rendered to a PNG via an
 * offscreen canvas) or uploaded. This is a picture baked into the page like
 * any other flattened annotation, NOT a cryptographic digital signature
 * (no certificate, no PKI, no tamper-evidence) — never present it as one.
 */
export interface SignatureAnnotation extends BaseAnnotation {
  type: 'signature';
  rect: FracRect;
  /** data: URL, PNG or JPEG. */
  imageDataUrl: string;
}

export type AnnotationObject =
  | TextAnnotation
  | ShapeAnnotation
  | LineAnnotation
  | PathAnnotation
  | WhiteoutAnnotation
  | MarkupAnnotation
  | RedactAnnotation
  | LinkAnnotation
  | NoteAnnotation
  | SignatureAnnotation;

export type AnnotationType = AnnotationObject['type'];

/** Annotation types that get baked into page content (vs. kept as real PDF annotation objects). */
export const FLATTENED_TYPES: ReadonlySet<AnnotationType> = new Set([
  'text',
  'rectangle',
  'ellipse',
  'line',
  'arrow',
  'draw',
  'whiteout',
  'markup',
  'redact',
  'signature',
]);

/** Page indices (0-based) that have at least one 'true' mode redaction pending. */
export function pagesNeedingTrueRedact(annotationsByPage: AnnotationObject[][]): number[] {
  return annotationsByPage.flatMap((annotations, i) =>
    annotations.some((a) => a.type === 'redact' && a.mode === 'true') ? [i] : [],
  );
}

let counter = 0;
export function newAnnotationId(): string {
  counter += 1;
  return `anno-${Date.now().toString(36)}-${counter.toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}

export const DEFAULT_FONT: FontStyle = { family: 'Helvetica', bold: false, italic: false };

export function boundingRectOfPoints(points: Point[]): { left: number; top: number; right: number; bottom: number } {
  const xs = points.map((p) => p.x);
  const ys = points.map((p) => p.y);
  return { left: Math.min(...xs), top: Math.min(...ys), right: Math.max(...xs), bottom: Math.max(...ys) };
}
