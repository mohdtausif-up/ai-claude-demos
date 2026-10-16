/**
 * Bakes a page's flattenable annotations (text, shapes, freehand draw,
 * whiteout, highlight/underline/strikethrough) into its actual content stream
 * via low-level pdf-lib drawing calls. Runs BEFORE crop/rotate/resize are
 * applied (see pdfBuild.ts), so annotations ride along with those transforms
 * exactly like the rest of the page's content — draw first, transform once.
 *
 * Links and sticky notes are *not* flattened here: they stay as real PDF
 * annotation objects (see pdfAnnotObjects.ts) so links remain clickable.
 */
import {
  BlendMode,
  closePath,
  fill,
  lineTo,
  LineCapStyle,
  moveTo,
  popGraphicsState,
  pushGraphicsState,
  setFillingRgbColor,
  setLineCap,
  setLineWidth,
  setStrokingRgbColor,
  StandardFonts,
  stroke,
  type PDFDocument,
  type PDFFont,
  type PDFImage,
  type PDFPage,
  type RGB,
} from 'pdf-lib';
import type {
  FontStyle,
  LineAnnotation,
  MarkupAnnotation,
  PathAnnotation,
  RedactAnnotation,
  ShapeAnnotation,
  SignatureAnnotation,
  TextAnnotation,
  WhiteoutAnnotation,
} from './annotations';
import { hexToColor } from './color';
import { parseDataUrl } from './dataUrl';
import { fracPointToPdf, fracRectToPdfBox, type PdfBox } from './geometry';
import type { PageRef } from './pageModel';

const FONT_MAP: Record<string, StandardFonts> = {
  'Helvetica|false|false': StandardFonts.Helvetica,
  'Helvetica|true|false': StandardFonts.HelveticaBold,
  'Helvetica|false|true': StandardFonts.HelveticaOblique,
  'Helvetica|true|true': StandardFonts.HelveticaBoldOblique,
  'Times|false|false': StandardFonts.TimesRoman,
  'Times|true|false': StandardFonts.TimesRomanBold,
  'Times|false|true': StandardFonts.TimesRomanItalic,
  'Times|true|true': StandardFonts.TimesRomanBoldItalic,
  'Courier|false|false': StandardFonts.Courier,
  'Courier|true|false': StandardFonts.CourierBold,
  'Courier|false|true': StandardFonts.CourierOblique,
  'Courier|true|true': StandardFonts.CourierBoldOblique,
};

/** Embeds each distinct standard font once per output document and reuses it. */
export class FontCache {
  private fonts = new Map<StandardFonts, Promise<PDFFont>>();
  constructor(private doc: PDFDocument) {}

  get(style: FontStyle): Promise<PDFFont> {
    const key = FONT_MAP[`${style.family}|${style.bold}|${style.italic}`] ?? StandardFonts.Helvetica;
    let font = this.fonts.get(key);
    if (!font) {
      font = this.doc.embedFont(key);
      this.fonts.set(key, font);
    }
    return font;
  }
}

/** Embeds each distinct signature image once per output document and reuses it. */
export class ImageCache {
  private images = new Map<string, Promise<PDFImage>>();
  constructor(private doc: PDFDocument) {}

  get(dataUrl: string): Promise<PDFImage> {
    let image = this.images.get(dataUrl);
    if (!image) {
      image = (async () => {
        const { mime, bytes } = parseDataUrl(dataUrl);
        return mime === 'image/jpeg' || mime === 'image/jpg' ? this.doc.embedJpg(bytes) : this.doc.embedPng(bytes);
      })();
      this.images.set(dataUrl, image);
    }
    return image;
  }
}

/** Word-wraps `text` to fit `maxWidth` points at `size`, honoring explicit newlines. */
function wrapLines(text: string, font: PDFFont, size: number, maxWidth: number): string[] {
  const lines: string[] = [];
  for (const paragraph of text.split('\n')) {
    const words = paragraph.split(/\s+/).filter(Boolean);
    if (words.length === 0) {
      lines.push('');
      continue;
    }
    let line = '';
    for (const word of words) {
      const candidate = line ? `${line} ${word}` : word;
      if (line && font.widthOfTextAtSize(candidate, size) > maxWidth) {
        lines.push(line);
        line = word;
      } else {
        line = candidate;
      }
    }
    lines.push(line);
  }
  return lines;
}

async function drawTextAnnotation(page: PDFPage, a: TextAnnotation, box: PdfBox, fonts: FontCache): Promise<void> {
  const font = await fonts.get(a.font);
  const pdfBox = fracRectToPdfBox(a.rect, box);
  const lineHeight = a.fontSize * 1.2;
  const lines = wrapLines(a.text, font, a.fontSize, pdfBox.width);
  const color = hexToColor(a.color);
  // Text is drawn top-down from the box's top edge; lines beyond the box are
  // clipped by simply not drawing them, rather than silently overflowing.
  const maxLines = Math.max(1, Math.floor(pdfBox.height / lineHeight));
  lines.slice(0, maxLines).forEach((line, i) => {
    const width = font.widthOfTextAtSize(line, a.fontSize);
    const x =
      a.align === 'center' ? pdfBox.x + (pdfBox.width - width) / 2 : a.align === 'right' ? pdfBox.x + pdfBox.width - width : pdfBox.x;
    const y = pdfBox.y + pdfBox.height - a.fontSize - i * lineHeight;
    page.drawText(line, { x, y, size: a.fontSize, font, color, opacity: a.opacity });
  });
}

function drawShapeAnnotation(page: PDFPage, a: ShapeAnnotation, box: PdfBox): void {
  const pdfBox = fracRectToPdfBox(a.rect, box);
  const shared = {
    color: a.fillColor ? hexToColor(a.fillColor) : undefined,
    opacity: a.fillColor ? a.opacity : undefined,
    borderColor: a.strokeColor ? hexToColor(a.strokeColor) : undefined,
    borderOpacity: a.strokeColor ? a.opacity : undefined,
    borderWidth: a.strokeColor ? a.strokeWidth : 0,
  };
  if (a.type === 'rectangle') {
    page.drawRectangle({ x: pdfBox.x, y: pdfBox.y, width: pdfBox.width, height: pdfBox.height, ...shared });
  } else {
    page.drawEllipse({
      x: pdfBox.x + pdfBox.width / 2,
      y: pdfBox.y + pdfBox.height / 2,
      xScale: pdfBox.width / 2,
      yScale: pdfBox.height / 2,
      ...shared,
    });
  }
}

function drawLineAnnotation(page: PDFPage, a: LineAnnotation, box: PdfBox): void {
  const start = fracPointToPdf(a.start, box);
  const end = fracPointToPdf(a.end, box);
  const color = hexToColor(a.strokeColor);
  page.drawLine({ start, end, thickness: a.strokeWidth, color, opacity: a.opacity, lineCap: LineCapStyle.Round });
  if (a.type === 'arrow') drawArrowhead(page, start, end, a.strokeWidth, color);
}

/**
 * A small filled triangle at `end`, oriented along the start→end direction.
 * Drawn with raw operators (pdf-lib's high-level draw* methods don't cover
 * arbitrary polygons), so — unlike the stroke itself — it's always fully
 * opaque; the opacity slider in the UI is scoped to shapes/text/highlight/
 * whiteout, which do go through the high-level API.
 */
function drawArrowhead(page: PDFPage, start: { x: number; y: number }, end: { x: number; y: number }, strokeWidth: number, color: RGB): void {
  const angle = Math.atan2(end.y - start.y, end.x - start.x);
  const length = Math.max(8, strokeWidth * 4);
  const spread = (25 * Math.PI) / 180;
  const back = (a: number) => ({ x: end.x - length * Math.cos(angle + a), y: end.y - length * Math.sin(angle + a) });
  const p1 = back(spread);
  const p2 = back(-spread);
  page.pushOperators(
    pushGraphicsState(),
    setFillingRgbColor(color.red, color.green, color.blue),
    moveTo(end.x, end.y),
    lineTo(p1.x, p1.y),
    lineTo(p2.x, p2.y),
    closePath(),
    fill(),
    popGraphicsState(),
  );
}

/** Freehand pen stroke, drawn with raw operators — see the arrowhead note on opacity above. */
function drawPathAnnotation(page: PDFPage, a: PathAnnotation, box: PdfBox): void {
  if (a.points.length < 2) return;
  const pts = a.points.map((p) => fracPointToPdf(p, box));
  const color = hexToColor(a.strokeColor);
  page.pushOperators(
    pushGraphicsState(),
    setLineCap(LineCapStyle.Round),
    setLineWidth(a.strokeWidth),
    setStrokingRgbColor(color.red, color.green, color.blue),
    moveTo(pts[0].x, pts[0].y),
    ...pts.slice(1).map((p) => lineTo(p.x, p.y)),
    stroke(),
    popGraphicsState(),
  );
}

function drawOpaqueBox(page: PDFPage, rect: Parameters<typeof fracRectToPdfBox>[0], color: string, opacity: number, box: PdfBox): void {
  const pdfBox = fracRectToPdfBox(rect, box);
  page.drawRectangle({ x: pdfBox.x, y: pdfBox.y, width: pdfBox.width, height: pdfBox.height, color: hexToColor(color), opacity });
}

function drawWhiteoutAnnotation(page: PDFPage, a: WhiteoutAnnotation, box: PdfBox): void {
  drawOpaqueBox(page, a.rect, a.color, a.opacity, box);
}

/**
 * Draws the cover box for BOTH redact modes — this is only the visual part.
 * 'true' mode additionally needs the page rasterized and its original vector
 * content discarded afterward; see trueRedact.ts, run as a later pass over
 * the pages this function flagged via pagesNeedingTrueRedact().
 */
function drawRedactAnnotation(page: PDFPage, a: RedactAnnotation, box: PdfBox): void {
  drawOpaqueBox(page, a.rect, a.color, 1, box);
}

function drawMarkupAnnotation(page: PDFPage, a: MarkupAnnotation, box: PdfBox): void {
  const color = hexToColor(a.color);
  for (const rect of a.rects) {
    const pdfBox = fracRectToPdfBox(rect, box);
    if (a.kind === 'highlight') {
      // Multiply blend mimics a real marker: it darkens the text underneath
      // instead of hiding it, so highlighted text stays readable.
      page.drawRectangle({ x: pdfBox.x, y: pdfBox.y, width: pdfBox.width, height: pdfBox.height, color, opacity: a.opacity, blendMode: BlendMode.Multiply });
    } else {
      const thickness = Math.max(1, pdfBox.height * 0.08);
      const y = a.kind === 'underline' ? pdfBox.y + thickness : pdfBox.y + pdfBox.height / 2;
      page.drawLine({ start: { x: pdfBox.x, y }, end: { x: pdfBox.x + pdfBox.width, y }, thickness, color, opacity: a.opacity });
    }
  }
}

async function drawSignatureAnnotation(page: PDFPage, a: SignatureAnnotation, box: PdfBox, images: ImageCache): Promise<void> {
  const image = await images.get(a.imageDataUrl);
  const pdfBox = fracRectToPdfBox(a.rect, box);
  page.drawImage(image, { x: pdfBox.x, y: pdfBox.y, width: pdfBox.width, height: pdfBox.height, opacity: a.opacity });
}

export async function drawAnnotations(page: PDFPage, ref: PageRef, fonts: FontCache, images: ImageCache): Promise<void> {
  if (ref.annotations.length === 0) return;
  const box = page.getCropBox();
  for (const a of ref.annotations) {
    switch (a.type) {
      case 'text':
        await drawTextAnnotation(page, a, box, fonts);
        break;
      case 'rectangle':
      case 'ellipse':
        drawShapeAnnotation(page, a, box);
        break;
      case 'line':
      case 'arrow':
        drawLineAnnotation(page, a, box);
        break;
      case 'draw':
        drawPathAnnotation(page, a, box);
        break;
      case 'whiteout':
        drawWhiteoutAnnotation(page, a, box);
        break;
      case 'markup':
        drawMarkupAnnotation(page, a, box);
        break;
      case 'redact':
        drawRedactAnnotation(page, a, box);
        break;
      case 'signature':
        await drawSignatureAnnotation(page, a, box, images);
        break;
      case 'link':
      case 'note':
        break; // handled separately as real PDF annotation objects
    }
  }
}
