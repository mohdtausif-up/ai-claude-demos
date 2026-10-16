import type { OutputFile } from './jobTypes';

/** Saves a file locally via an object URL — nothing is sent anywhere. */
export function downloadFile(file: OutputFile): void {
  const blob = new Blob([file.bytes as Uint8Array<ArrayBuffer>], { type: file.mime });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = file.name;
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  a.remove();
  // Give the browser time to start the download before revoking.
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

export function baseName(fileName: string): string {
  return fileName.replace(/\.pdf$/i, '') || 'document';
}

export function readFileBytes(file: File): Promise<Uint8Array> {
  return file.arrayBuffer().then((buf) => new Uint8Array(buf));
}
