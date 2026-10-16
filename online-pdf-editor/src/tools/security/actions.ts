/**
 * "Protect PDF" builds the current document exactly like a normal export
 * (including the true-redaction second pass, if needed), then runs the
 * result through qpdf's WASM encryption as one more step before download.
 *
 * "Remove password" needs no separate action: loadSources() (organize/
 * actions.ts) already decrypts password-protected files transparently at
 * open time, so a normal Download of an opened, previously-protected file
 * is already an unprotected copy.
 */
import { baseName, downloadFile } from '../../lib/download';
import { protectPdf, type ProtectOptions } from '../../lib/qpdfClient';
import { useDocumentStore } from '../../store/documentStore';
import { useUiStore } from '../../store/uiStore';
import { buildFinalDocumentBytes, reportError } from '../organize/actions';

const ui = () => useUiStore.getState();
const docStore = () => useDocumentStore.getState();

export async function protectAndDownload(opts: ProtectOptions): Promise<boolean> {
  const { doc, fileName } = docStore();
  if (!doc) return false;
  const outputName = `${baseName(fileName)}-protected.pdf`;
  try {
    const built = await buildFinalDocumentBytes(outputName);
    ui().setJob({ label: 'Encrypting PDF', progress: null });
    const encrypted = await protectPdf(built, opts);
    downloadFile({ name: outputName, bytes: encrypted, mime: 'application/pdf' });
    return true;
  } catch (err) {
    reportError(err);
    return false;
  } finally {
    ui().setJob(null);
  }
}
