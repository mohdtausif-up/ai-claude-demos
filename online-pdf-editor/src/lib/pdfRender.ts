/**
 * pdf.js wrapper: loads source documents and renders a PageRef (with its
 * rotation/crop/resize applied) to a canvas. Uses the same geometry helpers as
 * pdfBuild so the on-screen preview matches the exported file.
 */
import type { PDFDocumentProxy, RenderTask } from 'pdfjs-dist';
import { PdfError } from './errors';
import { displaySize, fitCentered, FULL_RECT, rotateFracRect, totalRotation } from './geometry';
import type { PageRef, SourceDoc } from './pageModel';
import { pdfjs, pdfjsAssetUrl as assetUrl } from './pdfjsSetup';

// `destroy()` lives on the loading task, not the resolved PDFDocumentProxy, so
// both are kept together and released via the task.
const docs = new Map<string, { task: pdfjs.PDFDocumentLoadingTask; promise: Promise<PDFDocumentProxy> }>();

export function openPdfJsDoc(source: SourceDoc): Promise<PDFDocumentProxy> {
  let entry = docs.get(source.id);
  if (!entry) {
    const task = pdfjs.getDocument({
      // pdf.js takes ownership of (detaches) the buffer it's given, so pass a copy.
      data: source.bytes.slice(),
      cMapUrl: assetUrl('cmaps'),
      cMapPacked: true,
      standardFontDataUrl: assetUrl('standard_fonts'),
      wasmUrl: assetUrl('wasm'),
      iccUrl: assetUrl('iccs'),
    });
    const promise = task.promise.catch((err: unknown) => {
      docs.delete(source.id);
      const name = (err as { name?: string })?.name;
      if (name === 'PasswordException') {
        throw new PdfError('encrypted', `${source.name} is password-protected. Opening encrypted PDFs isn't supported yet.`);
      }
      throw new PdfError('corrupt', `${source.name} couldn't be displayed — it may be damaged.`);
    });
    entry = { task, promise };
    docs.set(source.id, entry);
  }
  return entry.promise;
}

export async function closePdfJsDoc(docId: string): Promise<void> {
  const entry = docs.get(docId);
  docs.delete(docId);
  if (entry) await entry.task.destroy().catch(() => undefined);
}

export async function closeAllPdfJsDocs(): Promise<void> {
  await Promise.all([...docs.keys()].map(closePdfJsDoc));
}

// Many thumbnails can become visible at once; render a few at a time so the
// page the user is looking at isn't stuck behind dozens of others.
const MAX_CONCURRENT = 3;
let active = 0;
const queue: (() => void)[] = [];
function acquire(): Promise<void> {
  if (active < MAX_CONCURRENT) {
    active++;
    return Promise.resolve();
  }
  return new Promise((resolve) => queue.push(() => (active++, resolve())));
}
function release(): void {
  active--;
  queue.shift()?.();
}

/** Browsers refuse very large canvases; stay safely under common limits. */
const MAX_CANVAS_PIXELS = 16_000_000;

export interface RenderOptions {
  /** CSS pixels per PDF point. */
  cssScale: number;
  ignoreCrop?: boolean;
  ignoreResize?: boolean;
}

export interface RenderHandle {
  promise: Promise<void>;
  cancel: () => void;
}

export function renderPageRef(
  ref: PageRef,
  sources: Record<string, SourceDoc>,
  canvas: HTMLCanvasElement,
  opts: RenderOptions,
): RenderHandle {
  let cancelled = false;
  let task: RenderTask | null = null;

  const promise = (async () => {
    await acquire();
    try {
      if (cancelled) return;
      const target = displaySize(ref, opts);
      let ratio = opts.cssScale * (window.devicePixelRatio || 1);
      const pixels = target.width * target.height * ratio * ratio;
      if (pixels > MAX_CANVAS_PIXELS) ratio *= Math.sqrt(MAX_CANVAS_PIXELS / pixels);
      const width = Math.max(1, Math.round(target.width * ratio));
      const height = Math.max(1, Math.round(target.height * ratio));
      // CSS layout size must track cssScale alone — never devicePixelRatio or the
      // MAX_CANVAS_PIXELS clamp above, or the page would appear the wrong size on
      // high-DPI screens or very large pages.
      canvas.style.width = `${target.width * opts.cssScale}px`;
      canvas.style.height = `${target.height * opts.cssScale}px`;

      if (ref.source.kind === 'blank') {
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d')!;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, width, height);
        return;
      }

      const source = sources[ref.source.docId];
      if (!source) throw new PdfError('unknown', 'Page source is missing.');
      const doc = await openPdfJsDoc(source);
      const page = await doc.getPage(ref.source.pageIndex + 1);
      if (cancelled) return;

      const rotation = totalRotation(ref);
      const unit = page.getViewport({ scale: 1, rotation });
      const crop = rotateFracRect(opts.ignoreCrop ? FULL_RECT : (ref.crop ?? FULL_RECT), rotation);
      const content = { width: crop.width * unit.width, height: crop.height * unit.height };
      const fit = fitCentered(content, target);
      const scale = fit.scale * ratio;
      const viewport = page.getViewport({ scale, rotation });

      // Render off-screen, then swap in, so the old image stays visible meanwhile.
      const scratch = document.createElement('canvas');
      scratch.width = width;
      scratch.height = height;
      task = page.render({
        canvas: scratch,
        viewport,
        background: '#ffffff',
        transform: [
          1, 0, 0, 1,
          fit.offsetX * ratio - crop.left * unit.width * scale,
          fit.offsetY * ratio - crop.top * unit.height * scale,
        ],
      });
      await task.promise;
      if (cancelled) return;
      canvas.width = width;
      canvas.height = height;
      canvas.getContext('2d')!.drawImage(scratch, 0, 0);
    } catch (err) {
      if (cancelled || (err as { name?: string })?.name === 'RenderingCancelledException') return;
      throw err;
    } finally {
      release();
    }
  })();

  return {
    promise,
    cancel: () => {
      cancelled = true;
      task?.cancel();
    },
  };
}
