/**
 * User-facing operations that involve files or background jobs. Pure page-list
 * edits live in commands.ts; this module wires them to file I/O, workers,
 * progress, confirmation and error reporting.
 */
import { pagesNeedingTrueRedact } from '../../lib/annotations';
import { DEFAULT_DESIGN_SETTINGS } from '../../lib/design';
import { downloadFile, baseName, readFileBytes } from '../../lib/download';
import { isCancelled, PdfError, toUserMessage } from '../../lib/errors';
import { buildInWorker, inspectInWorker, type JobHandle } from '../../lib/jobs';
import { newId, pageRefsForSource, type PageRef, type SourceDoc, type SourcePageInfo } from '../../lib/pageModel';
import { closeAllPdfJsDocs, openPdfJsDoc } from '../../lib/pdfRender';
import { decryptPdf } from '../../lib/qpdfClient';
import { applyTrueRedactions } from '../../lib/trueRedact';
import { useDocumentStore } from '../../store/documentStore';
import { useUiStore } from '../../store/uiStore';
import type { Command } from '../../store/history';
import { deletePages, insertPages } from './commands';

/** Above this size, warn that the browser may run out of memory (PRD §6). */
export const LARGE_FILE_BYTES = 150 * 1024 * 1024;

const ui = () => useUiStore.getState();
const docStore = () => useDocumentStore.getState();

export function reportError(err: unknown): void {
  if (isCancelled(err)) {
    ui().toast('info', 'Cancelled.');
    return;
  }
  console.error(err);
  ui().toast('error', toUserMessage(err));
}

/** Runs a command, turning a rejected command into a friendly error toast. */
export function runCommand(command: Command): boolean {
  try {
    docStore().run(command);
    return true;
  } catch (err) {
    ui().toast('error', err instanceof Error ? err.message : String(err));
    return false;
  }
}

/** Runs a background job with the global progress overlay and cancel button. */
async function withJob<T>(label: string, start: (onProgress: (v: number, label?: string) => void) => JobHandle<T>): Promise<T> {
  let handle: JobHandle<T> | null = null;
  ui().setJob({ label, progress: null, cancel: () => handle?.cancel() });
  try {
    handle = start((progress, detail) => ui().updateJob({ progress, detail }));
    return await handle.promise;
  } finally {
    ui().setJob(null);
  }
}

function isPdfFile(file: File): boolean {
  return file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
}

/**
 * Password-protected PDFs can't be opened at all yet (pdfBuild.ts rejects
 * them outright) — so opening one goes through qpdf first: try the empty
 * password silently (covers "permissions-only" PDFs with no open password),
 * then prompt and retry until the user supplies a working password or gives
 * up. From here on the app only ever holds the decrypted bytes; re-adding
 * protection is a separate, explicit step (tools/security/actions.ts).
 */
async function unlockEncrypted(bytes: Uint8Array, fileName: string): Promise<Uint8Array | null> {
  try {
    return await decryptPdf(bytes, '');
  } catch {
    // Falls through to prompting — an empty password isn't sufficient.
  }
  let error: string | undefined;
  for (;;) {
    const password = await ui().askPassword({
      title: `"${fileName}" is password-protected`,
      message: 'Enter the password to open this file.',
      error,
    });
    if (password === null) return null;
    try {
      return await decryptPdf(bytes, password);
    } catch {
      error = 'Incorrect password. Try again.';
    }
  }
}

/**
 * Reads, validates (pdf-lib in a worker + pdf.js) and returns the given files
 * as sources. Bad files are reported individually; the rest still load.
 */
export async function loadSources(files: File[]): Promise<SourceDoc[]> {
  const sources: SourceDoc[] = [];
  for (const [i, file] of files.entries()) {
    if (!isPdfFile(file)) {
      ui().toast('error', `${file.name} is not a PDF file.`);
      continue;
    }
    if (file.size === 0) {
      ui().toast('error', `${file.name} is empty.`);
      continue;
    }
    if (file.size > LARGE_FILE_BYTES) {
      const ok = await ui().askConfirm({
        title: 'Very large file',
        message: `${file.name} is ${(file.size / 1024 / 1024).toFixed(0)} MB. Files this large may exceed your browser's memory and could make the tab crash. Continue?`,
        confirmLabel: 'Open anyway',
      });
      if (!ok) continue;
    }
    try {
      const counter = files.length > 1 ? ` (${i + 1} of ${files.length})` : '';
      let bytes = await readFileBytes(file);
      let pages: SourcePageInfo[];
      try {
        pages = await withJob(`Opening ${file.name}${counter}`, (p) => inspectInWorker(bytes, file.name, p));
      } catch (err) {
        if (!(err instanceof PdfError) || err.code !== 'encrypted') throw err;
        const unlocked = await unlockEncrypted(bytes, file.name);
        if (!unlocked) {
          ui().toast('info', `Skipped ${file.name} (no password entered).`);
          continue;
        }
        bytes = unlocked;
        pages = await withJob(`Opening ${file.name}${counter}`, (p) => inspectInWorker(bytes, file.name, p));
      }
      const source: SourceDoc = { id: newId('src'), name: file.name, bytes, pages };
      // Make sure pdf.js can display it too, so failures surface now, not as blank pages.
      await openPdfJsDoc(source);
      sources.push(source);
    } catch (err) {
      if (isCancelled(err)) {
        ui().toast('info', `Stopped opening ${file.name}.`);
        break;
      }
      reportError(err);
    }
  }
  return sources;
}

/** Opens files as a new document (several files are merged in order). */
export async function openFiles(files: File[]): Promise<boolean> {
  const sources = await loadSources(files);
  if (sources.length === 0) return false;
  const pages = sources.flatMap(pageRefsForSource);
  docStore().open(
    { sources: Object.fromEntries(sources.map((s) => [s.id, s])), pages, design: DEFAULT_DESIGN_SETTINGS, formValues: {}, flattenForms: false },
    sources[0].name,
  );
  ui().reset();
  ui().goToPage(pages[0].id);
  if (sources.length > 1) ui().toast('success', `Merged ${sources.length} files — ${pages.length} pages.`);
  return true;
}

/** Merge / insert-from-file: adds all pages of the files at `index`. */
export async function insertFiles(files: File[], index: number, verb = 'Insert'): Promise<void> {
  const sources = await loadSources(files);
  if (sources.length === 0) return;
  const refs = sources.flatMap(pageRefsForSource);
  const names = sources.length === 1 ? sources[0].name : `${sources.length} files`;
  if (runCommand(insertPages(refs, index, sources, `${verb} ${names}`))) {
    ui().setSelection(refs.map((r) => r.id), refs[0].id);
    ui().goToPage(refs[0].id);
    ui().toast('success', `Added ${refs.length} page${refs.length === 1 ? '' : 's'} from ${names}.`);
  }
}

export async function confirmAndDelete(ids: string[]): Promise<void> {
  const doc = docStore().doc;
  if (!doc || ids.length === 0) return;
  if (ids.length >= doc.pages.length) {
    ui().toast('error', "You can't delete every page — a PDF needs at least one page.");
    return;
  }
  const n = ids.length;
  const ok = await ui().askConfirm({
    title: `Delete ${n} page${n === 1 ? '' : 's'}?`,
    message: `The selected page${n === 1 ? '' : 's'} will be removed from the document. You can undo this with Ctrl+Z.`,
    confirmLabel: 'Delete',
    destructive: true,
  });
  if (!ok) return;
  const pages = doc.pages;
  const firstIndex = Math.min(...ids.map((id) => pages.findIndex((p) => p.id === id)));
  if (runCommand(deletePages(ids))) {
    const remaining = docStore().doc!.pages;
    ui().setSelection([], null);
    ui().goToPage(remaining[Math.min(firstIndex, remaining.length - 1)].id);
  }
}

async function buildAndDownload(
  label: string,
  outputs: { name: string; pages: PageRef[] }[],
  zipName?: string,
): Promise<boolean> {
  const doc = docStore().doc;
  if (!doc) return false;
  try {
    const files = await withJob(label, (onProgress) =>
      buildInWorker(doc.sources, outputs, { zipName, design: doc.design, formValues: doc.formValues, flattenForms: doc.flattenForms, onProgress }),
    );
    files.forEach(downloadFile);
    return true;
  } catch (err) {
    reportError(err);
    return false;
  }
}

/**
 * Builds the current document to final PDF bytes (applying true redaction's
 * second pass if needed), without downloading. Shared by exportDocument() and
 * the Security tools' "Protect" export, which needs these bytes to encrypt
 * before download rather than saving them directly. Throws on failure —
 * callers are responsible for reportError() and clearing the job overlay.
 */
async function buildFinalDocumentBytes(outputName: string): Promise<Uint8Array> {
  const doc = docStore().doc;
  if (!doc) throw new PdfError('empty', 'No document is open.');

  const truePages = pagesNeedingTrueRedact(doc.pages.map((p) => p.annotations));
  if (truePages.length === 0) {
    const files = await withJob('Saving PDF', (onProgress) =>
      buildInWorker(doc.sources, [{ name: outputName, pages: doc.pages }], {
        design: doc.design,
        formValues: doc.formValues,
        flattenForms: doc.flattenForms,
        onProgress,
      }),
    );
    return files[0].bytes;
  }

  // True redaction needs a second, main-thread pass (see trueRedact.ts) after
  // the normal worker build, so it doesn't fit the plain worker-only path —
  // progress is split across both passes instead.
  const passOne = await withJob('Saving PDF', (onProgress) =>
    buildInWorker(doc.sources, [{ name: outputName, pages: doc.pages }], {
      design: doc.design,
      formValues: doc.formValues,
      flattenForms: doc.flattenForms,
      onProgress: (f) => onProgress(f * 0.6),
    }),
  );
  ui().setJob({ label: 'Applying true redaction', progress: 0, detail: `Rasterizing ${truePages.length} page${truePages.length === 1 ? '' : 's'}…` });
  return applyTrueRedactions(passOne[0].bytes, truePages, { onProgress: (f) => ui().updateJob({ progress: 0.6 + f * 0.4 }) });
}

export async function exportDocument(): Promise<boolean> {
  const { doc, fileName } = docStore();
  if (!doc) return false;
  const outputName = `${baseName(fileName)}-edited.pdf`;
  try {
    const bytes = await buildFinalDocumentBytes(outputName);
    downloadFile({ name: outputName, bytes, mime: 'application/pdf' });
    return true;
  } catch (err) {
    reportError(err);
    return false;
  } finally {
    ui().setJob(null);
  }
}

export { buildFinalDocumentBytes };

/** Downloads each group of page positions as its own PDF (ZIP when more than one). */
export function splitDocument(groups: number[][]): Promise<boolean> {
  const { doc, fileName } = docStore();
  if (!doc) return Promise.resolve(false);
  if (groups.length === 0) {
    reportError(new PdfError('empty', 'Nothing to split.'));
    return Promise.resolve(false);
  }
  const base = baseName(fileName);
  const width = String(groups.length).length;
  const outputs = groups.map((group, i) => ({
    name: `${base}-part${String(i + 1).padStart(width, '0')}.pdf`,
    pages: group.map((idx) => doc.pages[idx]),
  }));
  return buildAndDownload(`Splitting into ${groups.length} files`, outputs, `${base}-split.zip`);
}

export function extractPages(positions: number[], label = 'extract'): Promise<boolean> {
  const { doc, fileName } = docStore();
  if (!doc || positions.length === 0) return Promise.resolve(false);
  return buildAndDownload('Extracting pages', [
    { name: `${baseName(fileName)}-${label}.pdf`, pages: positions.map((i) => doc.pages[i]) },
  ]);
}

export async function closeDocument(): Promise<void> {
  if (docStore().past.length > 0) {
    const ok = await ui().askConfirm({
      title: 'Close this document?',
      message: "Your changes haven't been saved. Download the PDF first if you want to keep them.",
      confirmLabel: 'Close without saving',
      destructive: true,
    });
    if (!ok) return;
  }
  docStore().close();
  ui().reset();
  await closeAllPdfJsDocs();
}
