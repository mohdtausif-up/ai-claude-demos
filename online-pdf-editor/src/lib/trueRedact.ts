/**
 * The second pass of "true redact" export. By the time this runs, pass one
 * (pdfBuild.ts) has already flattened every redaction's opaque cover box into
 * the page's content stream — same as a whiteout — so the page already
 * *looks* correct. What's still true is that the hidden text/vectors are
 * physically present underneath and recoverable (copy-paste, text extraction,
 * removing the box in another editor). This pass makes that recovery
 * impossible: it rasterizes each flagged page to an image exactly as it
 * already renders, then rebuilds the document with that image standing in
 * for the page's content, discarding the original vectors entirely.
 *
 * Requires a real `<canvas>` (via pdf.js), so — unlike the rest of the build
 * pipeline — this runs on the main thread, not in the Web Worker. It's also
 * only reached when the user has explicitly chosen "true redact", an
 * infrequent and already-labeled-as-slower operation, so that trade-off is
 * acceptable for v1.
 */
import { PDFDocument } from 'pdf-lib';
import { PdfError } from './errors';
import { pdfjs, pdfjsAssetUrl } from './pdfjsSetup';

/** Points-per-inch the page is rasterized at; 200 DPI keeps small text legible without huge files. */
const RASTER_DPI = 200;

export type ProgressFn = (fraction: number) => void;

async function rasterizePage(doc: pdfjs.PDFDocumentProxy, pageNumber: number): Promise<Uint8Array> {
  const page = await doc.getPage(pageNumber);
  // No `rotation` override: defaults to the page's own /Rotate, which is
  // exactly the final on-screen orientation pass one already baked in.
  const viewport = page.getViewport({ scale: RASTER_DPI / 72 });
  const canvas = document.createElement('canvas');
  canvas.width = Math.ceil(viewport.width);
  canvas.height = Math.ceil(viewport.height);
  const ctx = canvas.getContext('2d');
  if (!ctx) throw new PdfError('build-failed', 'This browser cannot rasterize pages for true redaction.');
  await page.render({ canvas, viewport, background: '#ffffff' }).promise;

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));
  if (!blob) throw new PdfError('build-failed', 'Could not rasterize a page for true redaction.');
  return new Uint8Array(await blob.arrayBuffer());
}

/**
 * Rebuilds `passOneBytes`, replacing each page index in `truePageIndices`
 * with a flattened raster image, and leaving every other page untouched.
 */
export async function applyTrueRedactions(
  passOneBytes: Uint8Array,
  truePageIndices: number[],
  opts: { onProgress?: ProgressFn } = {},
): Promise<Uint8Array> {
  if (truePageIndices.length === 0) return passOneBytes;
  const toRedact = new Set(truePageIndices);

  const task = pdfjs.getDocument({
    data: passOneBytes.slice(),
    cMapUrl: pdfjsAssetUrl('cmaps'),
    cMapPacked: true,
    standardFontDataUrl: pdfjsAssetUrl('standard_fonts'),
    wasmUrl: pdfjsAssetUrl('wasm'),
    iccUrl: pdfjsAssetUrl('iccs'),
  });
  const pdfjsDoc = await task.promise.catch(() => {
    throw new PdfError('build-failed', 'Could not prepare pages for true redaction.');
  });

  try {
    const src = await PDFDocument.load(passOneBytes);
    const out = await PDFDocument.create();
    out.setProducer('ClientPDF');
    out.setCreator('ClientPDF (in-browser)');

    const pageCount = src.getPageCount();
    const copies = await out.copyPages(src, Array.from({ length: pageCount }, (_, i) => i));

    for (let i = 0; i < pageCount; i++) {
      if (toRedact.has(i)) {
        const { width, height } = copies[i].getSize();
        const png = await rasterizePage(pdfjsDoc, i + 1);
        const image = await out.embedPng(png);
        out.addPage([width, height]).drawImage(image, { x: 0, y: 0, width, height });
      } else {
        out.addPage(copies[i]);
      }
      opts.onProgress?.((i + 1) / pageCount);
    }

    return await out.save({ useObjectStreams: true });
  } finally {
    await task.destroy();
  }
}
