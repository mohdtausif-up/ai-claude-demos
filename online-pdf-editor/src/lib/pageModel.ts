/**
 * The editor's document model.
 *
 * The open document is an ordered list of lightweight PageRefs that point into
 * one or more immutable source PDFs. Page operations (reorder, rotate, crop,
 * delete, insert, merge) only touch this list, which keeps them instant and
 * makes undo/redo cheap even for very large files. The real PDF is produced by
 * `buildPdf` (pdf-lib) only when the user exports, splits or extracts.
 */
import type { AnnotationObject } from './annotations';
import type { DesignSettings } from './design';
import type { FieldValue, FormFieldInfo } from './formFields';

export type Rotation = 0 | 90 | 180 | 270;

/** A rectangle in fractions (0..1) of a page, origin at the top-left corner. */
export interface FracRect {
  left: number;
  top: number;
  width: number;
  height: number;
}

export interface Size {
  width: number;
  height: number;
}

/** Per-page facts read from the source PDF at import time. */
export interface SourcePageInfo {
  /** Unrotated width/height of the page's visible box, in PDF points. */
  width: number;
  height: number;
  /** The page's own /Rotate value. */
  rotation: Rotation;
  /** Existing AcroForm fields whose widget appears on this page (see formFields.ts). */
  fields: FormFieldInfo[];
}

export interface SourceDoc {
  id: string;
  name: string;
  bytes: Uint8Array;
  pages: SourcePageInfo[];
}

export type PageSource =
  | { kind: 'pdf'; docId: string; pageIndex: number }
  | { kind: 'blank' };

export interface PageRef {
  id: string;
  source: PageSource;
  /** Unrotated size of the source page (points) and its intrinsic rotation. */
  base: SourcePageInfo;
  /** Rotation added by the user, on top of `base.rotation`. */
  rotation: Rotation;
  /** Crop in unrotated page space, relative to the original page. */
  crop: FracRect | null;
  /** Target size in unrotated page space (points); content is fit and centered. */
  resize: Size | null;
  /** User-added markup, in fractions of `base` (see lib/annotations.ts for the coordinate convention). */
  annotations: AnnotationObject[];
}

export interface DocState {
  sources: Record<string, SourceDoc>;
  pages: PageRef[];
  /** Document-wide watermark/page-number/header-footer/background settings (design.ts). */
  design: DesignSettings;
  /** User-entered AcroForm field values, keyed by field name (see formFields.ts). */
  formValues: Record<string, FieldValue>;
  /** Whether to bake form values into the page content and remove interactivity on export. */
  flattenForms: boolean;
}

let counter = 0;
/** crypto.randomUUID is unavailable on plain-http hosting, so don't depend on it. */
export function newId(prefix = 'id'): string {
  counter += 1;
  return `${prefix}-${Date.now().toString(36)}-${counter.toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}

export function pageRefsForSource(source: SourceDoc): PageRef[] {
  return source.pages.map((info, pageIndex) => ({
    id: newId('page'),
    source: { kind: 'pdf', docId: source.id, pageIndex },
    base: info,
    rotation: 0,
    crop: null,
    resize: null,
    annotations: [],
  }));
}

export function blankPageRef(size: Size): PageRef {
  return {
    id: newId('page'),
    source: { kind: 'blank' },
    base: { width: size.width, height: size.height, rotation: 0, fields: [] },
    rotation: 0,
    crop: null,
    resize: null,
    annotations: [],
  };
}

/** Standard paper sizes in portrait orientation, in PDF points. */
export const PAPER_SIZES: Record<string, Size> = {
  A3: { width: 841.89, height: 1190.55 },
  A4: { width: 595.28, height: 841.89 },
  A5: { width: 419.53, height: 595.28 },
  Letter: { width: 612, height: 792 },
  Legal: { width: 612, height: 1008 },
  Tabloid: { width: 792, height: 1224 },
};

export const MM_PER_POINT = 25.4 / 72;
