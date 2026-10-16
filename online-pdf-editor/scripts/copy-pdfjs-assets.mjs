// Copies pdf.js runtime assets (CMaps, standard fonts, image decoders, ICC
// profiles) into public/pdfjs so they are served by the static build and work
// offline. Runs automatically before `dev` and `build`.
import { cpSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const pdfjsDir = dirname(createRequire(import.meta.url).resolve('pdfjs-dist/package.json'));
const outDir = join(root, 'public', 'pdfjs');

mkdirSync(outDir, { recursive: true });
for (const dir of ['cmaps', 'standard_fonts', 'wasm', 'iccs']) {
  const from = join(pdfjsDir, dir);
  if (existsSync(from)) cpSync(from, join(outDir, dir), { recursive: true });
}
console.log('pdf.js assets copied to public/pdfjs');
