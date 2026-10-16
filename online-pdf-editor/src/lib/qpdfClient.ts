/**
 * Thin Promise wrapper around the vendored qpdf WASM build (public/qpdf/) —
 * the only client-side option for real PDF encryption, since pdf-lib doesn't
 * implement it. See public/qpdf/PROVENANCE.md for where this binary comes
 * from and why: it's a third-party build (not the official qpdf project),
 * disclosed rather than silently trusted.
 *
 * qpdf-worker.js speaks a small postMessage protocol (save a file into its
 * virtual FS, execute a qpdf CLI invocation, load a file back out) that this
 * module owns the main-thread side of — deliberately not reusing the
 * upstream src/qpdf.js wrapper, which drops `{type: 'error'}` messages from
 * failed save/load calls and would leave such a call's promise hanging forever.
 */
import { PdfError } from './errors';

type QpdfMessage =
  | { type: 'ready' }
  | { type: 'stdout'; line: string }
  | { type: 'saved'; filename: string }
  | { type: 'loaded'; filename: string; arrayBuffer: Uint8Array | null }
  | { type: 'executed'; status?: number; error?: string }
  | { type: 'error'; message: string };

function qpdfWorkerUrl(): string {
  return new URL('qpdf/qpdf-worker.js', document.baseURI).href;
}

/**
 * Runs one qpdf CLI invocation against `bytes` and returns the resulting
 * output file's bytes. `args` should NOT include the trailing `-- input
 * output`; that's appended automatically.
 */
export function runQpdf(bytes: Uint8Array, args: string[]): Promise<Uint8Array> {
  const inputName = 'input.pdf';
  const outputName = 'output.pdf';

  return new Promise<Uint8Array>((resolve, reject) => {
    let worker: Worker;
    try {
      worker = new Worker(qpdfWorkerUrl());
    } catch {
      reject(new PdfError('unknown', 'The password-protection engine could not be loaded.'));
      return;
    }

    let settled = false;
    const finish = (fn: () => void) => {
      if (settled) return;
      settled = true;
      worker.terminate();
      fn();
    };

    worker.onerror = () => finish(() => reject(new PdfError('unknown', 'The password-protection engine crashed while running.')));

    worker.onmessage = (event: MessageEvent<QpdfMessage>) => {
      const msg = event.data;
      switch (msg.type) {
        case 'ready':
          worker.postMessage({ type: 'save', filename: inputName, arrayBuffer: bytes });
          worker.postMessage({ type: 'execute', args: [...args, '--', inputName, outputName] });
          worker.postMessage({ type: 'load', filename: outputName });
          break;
        case 'executed':
          if (msg.status !== 0) {
            finish(() => reject(new PdfError('build-failed', msg.error ?? 'The password-protection engine reported an error.')));
          }
          break;
        case 'loaded':
          if (msg.arrayBuffer) finish(() => resolve(new Uint8Array(msg.arrayBuffer!)));
          // If execute failed, the 'executed' branch above already rejected
          // and terminated the worker; this racing 'loaded' (empty result,
          // since output.pdf was never written) is then a no-op via `settled`.
          break;
        case 'error':
          finish(() => reject(new PdfError('build-failed', msg.message)));
          break;
        case 'stdout':
          break;
      }
    };
  });
}

export type PermissionLevel = 'none' | 'form' | 'all';
export type PrintLevel = 'none' | 'low' | 'full';

export interface ProtectOptions {
  userPassword: string;
  ownerPassword: string;
  print: PrintLevel;
  modify: PermissionLevel;
  allowCopy: boolean;
}

/** Encrypts `bytes` with the given passwords and permissions (AES-256). */
export function protectPdf(bytes: Uint8Array, opts: ProtectOptions): Promise<Uint8Array> {
  return runQpdf(bytes, [
    '--encrypt',
    opts.userPassword,
    opts.ownerPassword || opts.userPassword,
    '256',
    `--print=${opts.print}`,
    `--modify=${opts.modify}`,
    `--extract=${opts.allowCopy ? 'y' : 'n'}`,
  ]);
}

/**
 * Decrypts `bytes` with `password` (empty string for a permissions-only PDF
 * with no open password). This vendored qpdf build only accepts the
 * `--password=value` form (confirmed by testing directly against the
 * worker) — the `--password value` two-token form shown in upstream's own
 * README is rejected with "password must be given as --password=password".
 */
export function decryptPdf(bytes: Uint8Array, password: string): Promise<Uint8Array> {
  return runQpdf(bytes, ['--decrypt', `--password=${password}`]);
}
