/// <reference lib="webworker" />
/**
 * pdf-lib work (validating imports, writing output) runs here so large files
 * never freeze the UI. One worker per job: cancelling = terminating it.
 */
import { zipSync } from 'fflate';
import { serializeError } from '../lib/errors';
import { buildMany, inspectPdf } from '../lib/pdfBuild';
import type { JobRequest, JobResponse } from '../lib/jobTypes';

const post = (msg: JobResponse, transfer: Transferable[] = []) =>
  (self as unknown as DedicatedWorkerGlobalScope).postMessage(msg, transfer);

self.onmessage = async (event: MessageEvent<JobRequest>) => {
  const req = event.data;
  const onProgress = (value: number) => post({ type: 'progress', value });
  try {
    if (req.type === 'inspect') {
      const result = await inspectPdf(req.bytes, req.name);
      post({ type: 'done', result: { kind: 'inspect', pages: result.pages } });
      return;
    }

    const files = await buildMany(req.sources, req.outputs, {
      onProgress,
      sourceNames: req.sourceNames,
      design: req.design,
      formValues: req.formValues,
      flattenForms: req.flattenForms,
    });
    if (req.zipName && files.length > 1) {
      post({ type: 'progress', value: 1, label: 'Creating ZIP…' });
      // PDFs are already compressed; store them to keep zipping fast.
      const zip = zipSync(Object.fromEntries(files.map((f) => [f.name, [f.bytes, { level: 0 }]])));
      post({ type: 'done', result: { kind: 'files', files: [{ name: req.zipName, bytes: zip, mime: 'application/zip' }] } }, [zip.buffer]);
      return;
    }
    const out = files.map((f) => ({ ...f, mime: 'application/pdf' }));
    post({ type: 'done', result: { kind: 'files', files: out } }, out.map((f) => f.bytes.buffer));
  } catch (err) {
    post({ type: 'error', error: serializeError(err) });
  }
};
