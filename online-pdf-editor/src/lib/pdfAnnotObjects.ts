/**
 * Adds real PDF annotation objects (Link, Text/"sticky note") to a page —
 * unlike the markup in flattenAnnotations.ts, these are kept as actual
 * interactive PDF annotations rather than baked into the content stream, so a
 * link stays clickable and a note stays a collapsible comment in any viewer.
 *
 * Must run AFTER crop/resize (pdfBuild.ts's applyTransforms): a page's
 * /Annots rects live in the page's coordinate system directly and are *not*
 * affected by the content-stream transforms `scaleContent`/`translateContent`
 * apply, so resize is compensated for manually here (crop and rotation need
 * no compensation — see the comments below).
 */
import { PDFHexString, PDFString, type PDFDocument, type PDFPage } from 'pdf-lib';
import type { LinkAnnotation, NoteAnnotation } from './annotations';
import { fitCentered, fracPointToPdf, fracRectToPdfBox, type PdfBox } from './geometry';
import type { PageRef } from './pageModel';

/** The note icon is drawn at a fixed size regardless of page scale, like most PDF viewers. */
const NOTE_ICON_SIZE = 20;

/**
 * The box link/note rects should be computed against: crop only changes what
 * part of the (unmoved) coordinate system is visible, so it needs no
 * compensation; a page's /Rotate is applied by viewers to annotations the
 * same way it's applied to content, so that needs none either. Resize is the
 * one transform that actually moves content-stream coordinates (via
 * scaleContent/translateContent) without touching /Annots, so it's the one
 * case this function corrects for.
 */
function finalBox(originalBox: PdfBox, ref: PageRef): { box: PdfBox; scale: number } {
  if (!ref.resize) return { box: originalBox, scale: 1 };
  // applyTransforms (pdfBuild.ts) fits the *cropped* box into the resize
  // target, not the full original box — the fit scale/offset must be derived
  // from the same cropped box, or a page with both crop and resize gets a
  // mismatched scale here and every link/note on it lands in the wrong place.
  const cropBox = ref.crop ? fracRectToPdfBox(ref.crop, originalBox) : originalBox;
  const fit = fitCentered({ width: cropBox.width, height: cropBox.height }, ref.resize);
  return {
    box: {
      x: (originalBox.x - cropBox.x) * fit.scale + fit.offsetX,
      y: (originalBox.y - cropBox.y) * fit.scale + fit.offsetY,
      width: originalBox.width * fit.scale,
      height: originalBox.height * fit.scale,
    },
    scale: fit.scale,
  };
}

export function addLinkAnnotation(doc: PDFDocument, page: PDFPage, a: LinkAnnotation, originalBox: PdfBox, ref: PageRef): void {
  const { box } = finalBox(originalBox, ref);
  const rect = fracRectToPdfBox(a.rect, box);
  const dict = doc.context.obj({
    Type: 'Annot',
    Subtype: 'Link',
    Rect: [rect.x, rect.y, rect.x + rect.width, rect.y + rect.height],
    Border: [0, 0, 0],
    A: doc.context.obj({ Type: 'Action', S: 'URI', URI: PDFString.of(a.url) }),
  });
  page.node.addAnnot(doc.context.register(dict));
}

export function addNoteAnnotation(doc: PDFDocument, page: PDFPage, a: NoteAnnotation, originalBox: PdfBox, ref: PageRef): void {
  const { box, scale } = finalBox(originalBox, ref);
  const at = fracPointToPdf(a.at, box);
  const half = (NOTE_ICON_SIZE * scale) / 2;
  const [r, g, b] = hexToUnitRgb(a.color);
  const dict = doc.context.obj({
    Type: 'Annot',
    Subtype: 'Text',
    Rect: [at.x - half, at.y - half, at.x + half, at.y + half],
    Contents: PDFHexString.fromText(a.text),
    Name: 'Comment',
    C: [r, g, b],
    Open: false,
  });
  page.node.addAnnot(doc.context.register(dict));
}

function hexToUnitRgb(hex: string): [number, number, number] {
  const clean = hex.replace('#', '').padEnd(6, '0');
  const int = Number.parseInt(clean, 16);
  return [((int >> 16) & 255) / 255, ((int >> 8) & 255) / 255, (int & 255) / 255];
}

/** Adds any non-flattened (link/note) annotations for this page. Call after applyTransforms. */
export function addPdfAnnotationObjects(doc: PDFDocument, page: PDFPage, ref: PageRef, originalBox: PdfBox): void {
  for (const a of ref.annotations) {
    if (a.type === 'link') addLinkAnnotation(doc, page, a, originalBox, ref);
    else if (a.type === 'note') addNoteAnnotation(doc, page, a, originalBox, ref);
  }
}
