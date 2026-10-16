/** Shared pdf.js bootstrap — worker URL and app-asset paths, used by every pdf.js call site. */
import * as pdfjs from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

/**
 * CMaps, standard fonts and decoders are copied to public/pdfjs by
 * scripts/copy-pdfjs-assets.mjs. They're app assets, not user data. The URL
 * must be absolute: pdf.js fetches them from inside its worker.
 */
export function pdfjsAssetUrl(dir: string): string {
  return new URL(`pdfjs/${dir}/`, document.baseURI).href;
}

export { pdfjs };
