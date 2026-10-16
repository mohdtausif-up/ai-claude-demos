/**
 * pdf-lib wrappers: validate/inspect incoming PDFs and write the edited page
 * list back out as a real PDF. Pure (no DOM), so it runs in a Web Worker and in
 * unit tests under Node.
 */
import { PDFDocument, PDFName, PDFRawStream, degrees, type PDFPage } from 'pdf-lib';
import { backgroundApplies, drawBackground, drawDesignOverlays } from './drawDesign';
import type { DesignSettings } from './design';
import { PdfError } from './errors';
import { drawAnnotations, FontCache, ImageCache } from './flattenAnnotations';
import { applyFormValues, carryFormWidgets, extractFormFields, reconcileCopiedFormFields, type FieldValue } from './formFields';
import { fitCentered, fracRectToPdfBox, normalizeRotation, totalRotation } from './geometry';
import { addPdfAnnotationObjects } from './pdfAnnotObjects';
import type { PageRef, SourcePageInfo } from './pageModel';

export interface InspectResult {
  pages: SourcePageInfo[];
}

export type ProgressFn = (fraction: number) => void;

const PDF_HEADER = [0x25, 0x50, 0x44, 0x46, 0x2d]; // "%PDF-"

/** PDF readers accept the header anywhere in the first 1KB. */
export function hasPdfHeader(bytes: Uint8Array): boolean {
  const limit = Math.min(bytes.length - PDF_HEADER.length, 1024);
  outer: for (let i = 0; i <= limit; i++) {
    for (let j = 0; j < PDF_HEADER.length; j++) {
      if (bytes[i + j] !== PDF_HEADER[j]) continue outer;
    }
    return true;
  }
  return false;
}

async function loadSource(bytes: Uint8Array, name = 'This file'): Promise<PDFDocument> {
  if (!hasPdfHeader(bytes)) {
    throw new PdfError('not-pdf', `${name} is not a PDF file.`);
  }
  let doc: PDFDocument;
  try {
    // pdf-lib's own encryption error doesn't survive `instanceof` checks, so
    // load with ignoreEncryption and inspect the flag instead.
    doc = await PDFDocument.load(bytes, { updateMetadata: false, throwOnInvalidObject: false, ignoreEncryption: true });
  } catch {
    throw new PdfError('corrupt', `${name} appears to be damaged and couldn't be read.`);
  }
  if (doc.isEncrypted) {
    // Callers (organize/actions.ts's loadSources) catch this specific code and
    // decrypt via qpdf before retrying — pdf-lib itself has no decryption
    // support, so by the time a document reaches buildPdf() it must already
    // be plain bytes.
    throw new PdfError('encrypted', `${name} is password-protected.`);
  }
  return doc;
}

export async function inspectPdf(bytes: Uint8Array, name?: string): Promise<InspectResult> {
  const doc = await loadSource(bytes, name);
  let pages: PDFPage[];
  try {
    pages = doc.getPages();
  } catch {
    throw new PdfError('corrupt', `${name ?? 'This file'} has a damaged page tree and couldn't be read.`);
  }
  if (pages.length === 0) throw new PdfError('empty', `${name ?? 'This file'} has no pages.`);
  const fieldsByPage = extractFormFields(doc, pages);
  return {
    pages: pages.map((page, i) => {
      const box = page.getCropBox();
      return {
        width: Math.abs(box.width),
        height: Math.abs(box.height),
        rotation: normalizeRotation(page.getRotation().angle),
        fields: fieldsByPage[i],
      };
    }),
  };
}

/** Applies a PageRef's crop, rotation and resize to a page already in the output doc. */
function applyTransforms(page: PDFPage, ref: PageRef): void {
  if (ref.crop) {
    const box = fracRectToPdfBox(ref.crop, page.getCropBox());
    page.setCropBox(box.x, box.y, box.width, box.height);
  }

  page.setRotation(degrees(totalRotation(ref)));

  if (ref.resize) {
    const box = page.getCropBox();
    const target = ref.resize;
    const fit = fitCentered({ width: box.width, height: box.height }, target);
    // scaleContent wraps first (inner), translateContent wraps outside it, so a
    // content point p maps to scale * p + translation.
    page.scaleContent(fit.scale, fit.scale);
    page.translateContent(fit.offsetX - box.x * fit.scale, fit.offsetY - box.y * fit.scale);
    page.setMediaBox(0, 0, target.width, target.height);
    page.setCropBox(0, 0, target.width, target.height);
    page.setBleedBox(0, 0, target.width, target.height);
    page.setTrimBox(0, 0, target.width, target.height);
    page.setArtBox(0, 0, target.width, target.height);
  }
}

export interface BuildOptions {
  onProgress?: ProgressFn;
  /** Names of sources, for error messages. */
  sourceNames?: Record<string, string>;
  /** Already-loaded pdf-lib docs, shared across several builds (e.g. split). */
  cache?: Map<string, PDFDocument>;
  /** Document-wide watermark/page-number/header-footer/background settings. */
  design?: DesignSettings;
  /** This output's file name, for the {filename} placeholder in design text. */
  outputName?: string;
  /** User-entered AcroForm field values, keyed by field name. */
  formValues?: Record<string, FieldValue>;
  /** Bake formValues into the page content and remove interactivity. */
  flattenForms?: boolean;
}

/** Writes the given page list as a new PDF. */
export async function buildPdf(
  sources: Record<string, Uint8Array>,
  pages: PageRef[],
  opts: BuildOptions = {},
): Promise<Uint8Array> {
  if (pages.length === 0) throw new PdfError('empty', 'There are no pages to save.');
  const out = await PDFDocument.create();
  out.setProducer('ClientPDF');
  out.setCreator('ClientPDF (in-browser)');

  const cache = opts.cache ?? new Map<string, PDFDocument>();
  const getSource = async (docId: string) => {
    let doc = cache.get(docId);
    if (!doc) {
      const bytes = sources[docId];
      if (!bytes) throw new PdfError('build-failed', 'A page refers to a file that is no longer loaded.');
      doc = await loadSource(bytes, opts.sourceNames?.[docId]);
      cache.set(docId, doc);
    }
    return doc;
  };

  // Copy pages in batches per source. One copyPages call dedupes identical
  // source pages to a single node, so repeated pages go into later rounds to
  // give every occurrence its own independent copy.
  const copied = new Array<PDFPage | null>(pages.length).fill(null);
  const pending = new Map<string, number[]>(); // docId → positions in `pages`
  pages.forEach((ref, i) => {
    if (ref.source.kind === 'pdf') {
      const list = pending.get(ref.source.docId) ?? [];
      list.push(i);
      pending.set(ref.source.docId, list);
    }
  });
  for (const [docId, positions] of pending) {
    const src = await getSource(docId);
    let remaining = positions;
    while (remaining.length > 0) {
      const seen = new Set<number>();
      const round: number[] = [];
      const next: number[] = [];
      for (const pos of remaining) {
        const idx = (pages[pos].source as { pageIndex: number }).pageIndex;
        if (seen.has(idx)) next.push(pos);
        else {
          seen.add(idx);
          round.push(pos);
        }
      }
      const indices = round.map((pos) => (pages[pos].source as { pageIndex: number }).pageIndex);
      if (indices.some((idx) => idx >= src.getPageCount())) {
        throw new PdfError('build-failed', 'A page refers to a page number that no longer exists in its file.');
      }
      const result = await out.copyPages(src, indices);
      round.forEach((pos, k) => (copied[pos] = result[k]));
      remaining = next;
    }
  }

  const fonts = new FontCache(out);
  const images = new ImageCache(out);
  const fileName = opts.outputName ?? '';
  for (let i = 0; i < pages.length; i++) {
    const ref = pages[i];
    const needsBackground = backgroundApplies(opts.design, i, pages.length);
    let page: PDFPage;
    if (ref.source.kind === 'blank') {
      page = out.addPage([ref.base.width, ref.base.height]);
      if (needsBackground) drawBackground(page, page.getCropBox(), opts.design!.background);
    } else if (needsBackground) {
      // pdf-lib only ever appends drawing operators, so a background fill
      // drawn on the original page would sit on top of its content, not
      // behind it. Instead: embed the original page's content as a Form
      // XObject, draw the background on a fresh page first, then place the
      // embedded content on top — giving a real behind-the-content fill.
      // (Trade-off: any pre-existing PDF-native annotations on the source
      // page — e.g. links already in the uploaded file — don't carry over
      // for pages where background is applied; this app's own links/notes
      // are unaffected, since those are added fresh below either way.)
      const srcPage = copied[i]!;
      // A page with no drawn content at all (e.g. a blank page carrying only
      // form fields) has no /Contents stream — embedPage() requires one.
      if (!srcPage.node.Contents()) {
        srcPage.node.set(PDFName.of('Contents'), out.context.register(PDFRawStream.of(out.context.obj({}), new Uint8Array(0))));
      }
      const cropBox = srcPage.getCropBox();
      // embedPage() defaults to the page's MediaBox (assumed to start at
      // (0,0)) when no boundingBox is given — wrong whenever a page's own
      // CropBox differs from its MediaBox. Pass the actual crop box
      // explicitly so the embedded content is captured and positioned
      // correctly even then.
      const embedded = await out.embedPage(srcPage, {
        left: cropBox.x,
        bottom: cropBox.y,
        right: cropBox.x + cropBox.width,
        top: cropBox.y + cropBox.height,
      });
      page = out.addPage([cropBox.width, cropBox.height]);
      drawBackground(page, page.getCropBox(), opts.design!.background);
      page.drawPage(embedded, { x: 0, y: 0, width: cropBox.width, height: cropBox.height });
      // Widget annotations (AcroForm fields) are PDF-native annotations too,
      // but unlike links/notes nothing re-adds them afterward — without this
      // they'd silently vanish from the output on any page with a background.
      carryFormWidgets(srcPage, page, cropBox);
    } else {
      page = out.addPage(copied[i]!);
    }
    const originalBox = page.getCropBox();
    // Annotations are drawn onto the page's original content BEFORE crop/
    // rotate/resize, so they ride along with those transforms exactly like
    // the rest of the page's content instead of needing separate handling.
    await drawAnnotations(page, ref, fonts, images);
    if (opts.design) await drawDesignOverlays(page, originalBox, opts.design, i, pages.length, fonts, fileName);
    applyTransforms(page, ref);
    // Links/notes stay real PDF annotation objects (not flattened content), so
    // they're added after the transform and independently corrected for it.
    addPdfAnnotationObjects(out, page, ref, originalBox);
    opts.onProgress?.((i + 1) / pages.length);
  }

  // AcroForm is document-wide, not per-page, so values/flattening apply once
  // here rather than inside the per-page loop above. Reconciliation runs
  // whenever any source page had fields at all — even if the user never
  // touched this app's fill UI, a form PDF that's merely rotated/exported
  // here shouldn't silently lose its fillability.
  if (pages.some((p) => p.base.fields.length > 0)) {
    reconcileCopiedFormFields(out);
    if (opts.formValues && Object.keys(opts.formValues).length > 0) applyFormValues(out, opts.formValues);
    if (opts.flattenForms) {
      try {
        out.getForm().flatten();
      } catch {
        // No AcroForm survived the copy (e.g. all source fields were dropped) — nothing to flatten.
      }
    }
  }

  try {
    return await out.save({ useObjectStreams: true });
  } catch {
    throw new PdfError('build-failed', 'The PDF could not be written. One of the source files may be damaged.');
  }
}

export interface OutputSpec {
  name: string;
  pages: PageRef[];
}

/** Builds several PDFs (split / multi-extract), parsing each source once. */
export async function buildMany(
  sources: Record<string, Uint8Array>,
  outputs: OutputSpec[],
  opts: Omit<BuildOptions, 'cache'> = {},
): Promise<{ name: string; bytes: Uint8Array }[]> {
  const cache = new Map<string, PDFDocument>();
  const total = outputs.reduce((n, o) => n + o.pages.length, 0);
  let done = 0;
  const results: { name: string; bytes: Uint8Array }[] = [];
  for (const output of outputs) {
    const bytes = await buildPdf(sources, output.pages, {
      ...opts,
      cache,
      outputName: output.name,
      onProgress: (f) => opts.onProgress?.((done + f * output.pages.length) / total),
    });
    done += output.pages.length;
    results.push({ name: output.name, bytes });
  }
  return results;
}
