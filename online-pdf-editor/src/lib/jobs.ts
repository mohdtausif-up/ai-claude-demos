import type { DesignSettings } from './design';
import { PdfError } from './errors';
import type { FieldValue } from './formFields';
import type { JobRequest, JobResponse, JobResult, OutputFile } from './jobTypes';
import type { PageRef, SourceDoc, SourcePageInfo } from './pageModel';

export interface JobHandle<T> {
  promise: Promise<T>;
  cancel: () => void;
}

export type JobProgress = (value: number, label?: string) => void;

function runJob(req: JobRequest, onProgress?: JobProgress): JobHandle<JobResult> {
  const worker = new Worker(new URL('../workers/pdfOps.worker.ts', import.meta.url), { type: 'module' });
  let settle: { resolve: (r: JobResult) => void; reject: (e: unknown) => void };
  const promise = new Promise<JobResult>((resolve, reject) => (settle = { resolve, reject }));

  worker.onmessage = (event: MessageEvent<JobResponse>) => {
    const msg = event.data;
    if (msg.type === 'progress') {
      onProgress?.(msg.value, msg.label);
      return;
    }
    worker.terminate();
    if (msg.type === 'done') settle.resolve(msg.result);
    else settle.reject(new PdfError(msg.error.code, msg.error.message));
  };
  worker.onerror = (event) => {
    event.preventDefault();
    worker.terminate();
    settle.reject(new PdfError('unknown', 'The background worker crashed. The file may be too large for this browser.'));
  };
  // Structured clone copies the byte arrays; the editor keeps its own copies.
  worker.postMessage(req);

  return {
    promise,
    cancel: () => {
      worker.terminate();
      settle.reject(new PdfError('cancelled', 'Cancelled.'));
    },
  };
}

export function inspectInWorker(bytes: Uint8Array, name: string, onProgress?: JobProgress): JobHandle<SourcePageInfo[]> {
  const job = runJob({ type: 'inspect', bytes, name }, onProgress);
  return {
    cancel: job.cancel,
    promise: job.promise.then((r) => (r.kind === 'inspect' ? r.pages : Promise.reject(new Error('bad result')))),
  };
}

export function buildInWorker(
  sources: Record<string, SourceDoc>,
  outputs: { name: string; pages: PageRef[] }[],
  opts: { zipName?: string; design?: DesignSettings; formValues?: Record<string, FieldValue>; flattenForms?: boolean; onProgress?: JobProgress } = {},
): JobHandle<OutputFile[]> {
  // Only send the sources the requested pages actually use.
  const used = new Set(outputs.flatMap((o) => o.pages.flatMap((p) => (p.source.kind === 'pdf' ? [p.source.docId] : []))));
  const bytes: Record<string, Uint8Array> = {};
  const names: Record<string, string> = {};
  for (const id of used) {
    bytes[id] = sources[id].bytes;
    names[id] = sources[id].name;
  }
  const job = runJob(
    {
      type: 'build',
      sources: bytes,
      sourceNames: names,
      outputs,
      zipName: opts.zipName,
      design: opts.design,
      formValues: opts.formValues,
      flattenForms: opts.flattenForms,
    },
    opts.onProgress,
  );
  return {
    cancel: job.cancel,
    promise: job.promise.then((r) => (r.kind === 'files' ? r.files : Promise.reject(new Error('bad result')))),
  };
}
