import { expect, test } from '@playwright/test';
import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { PDFArray, PDFDocument, PDFName, PDFRawStream } from 'pdf-lib';
import { makeTestPdf } from './fixtures';

let pdfPath: string;

test.beforeAll(async () => {
  const dir = await mkdtemp(join(tmpdir(), 'clientpdf-e2e-'));
  pdfPath = join(dir, 'sample.pdf');
  await writeFile(pdfPath, await makeTestPdf(2));
});

/** Decoded content-stream text for page 0 (see e2e/annotate.spec.ts for the same helper). */
async function firstPageContent(bytes: Uint8Array): Promise<string> {
  const doc = await PDFDocument.load(bytes);
  const { inflateSync } = await import('node:zlib');
  const page = doc.getPages()[0];
  const contents = page.node.Contents();
  const refs = contents instanceof PDFArray ? contents.asArray() : [page.node.get(PDFName.of('Contents'))];
  return refs
    .map((ref) => {
      const stream = doc.context.lookup(ref) as PDFRawStream;
      const raw = Buffer.from(stream.contents);
      return (stream.dict.get(PDFName.of('Filter')) ? inflateSync(raw) : raw).toString('latin1');
    })
    .join('\n');
}

function hexOf(text: string): string {
  return Buffer.from(text, 'latin1').toString('hex');
}

async function downloadAndRead(page: import('@playwright/test').Page, fileName: string): Promise<Uint8Array> {
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: /download pdf/i }).click()]);
  const outPath = join(tmpdir(), fileName);
  await download.saveAs(outPath);
  return readFile(outPath);
}

test('adds a watermark and page numbers that survive export', async ({ page }) => {
  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(pdfPath);
  await page.getByRole('button', { name: 'Design…' }).click();

  await page.getByRole('checkbox', { name: 'Watermark' }).check();
  await page.getByLabel('Text').fill('DRAFT COPY');

  await page.getByRole('checkbox', { name: 'Page numbers' }).check();

  await page.getByRole('button', { name: 'Apply' }).click();

  const bytes = await downloadAndRead(page, 'clientpdf-design.pdf');
  const content = await firstPageContent(bytes);
  expect(content.toLowerCase()).toContain(hexOf('DRAFT COPY').toLowerCase());
  expect(content.toLowerCase()).toContain(hexOf('Page 1 of 2').toLowerCase());
});

test('background color and header/footer survive export', async ({ page }) => {
  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(pdfPath);
  await page.getByRole('button', { name: 'Design…' }).click();

  await page.getByRole('checkbox', { name: 'Header & footer' }).check();
  const footerLeftInputs = page.locator('input.w-full.rounded-md.border-slate-300');
  await footerLeftInputs.nth(3).fill('Footer text');

  await page.getByRole('checkbox', { name: 'Background color' }).check();

  await page.getByRole('button', { name: 'Apply' }).click();

  const bytes = await downloadAndRead(page, 'clientpdf-design-bg.pdf');
  const doc = await PDFDocument.load(bytes);
  // The original page content moved into an embedded Form XObject so the
  // background fill can sit genuinely behind it — see pdfBuild.ts.
  expect(doc.getPages()[0].node.Resources()?.get(PDFName.of('XObject'))).toBeTruthy();
  const content = await firstPageContent(bytes);
  expect(content.toLowerCase()).toContain(hexOf('Footer text').toLowerCase());
});
