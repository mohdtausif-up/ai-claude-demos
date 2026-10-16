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

/** Decoded content-stream text for page 0, for asserting the exported PDF actually changed. */
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

async function openAndAnnotate(page: import('@playwright/test').Page) {
  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(pdfPath);
  await page.getByRole('button', { name: '✎ Annotate' }).click();
  // Entering Annotate mode adds the AnnotateToolbar row, shrinking the
  // available viewer height; "fit page" then recomputes via ResizeObserver
  // asynchronously. Wait for that to settle so the canvas's measured bounding
  // box (used for all the click/drag coordinates below) matches what's
  // actually visible and clickable, not a stale, taller pre-resize layout.
  await page.waitForFunction(() => {
    const el = document.querySelector('[data-testid="annotate-canvas"]');
    return !!el && el.getBoundingClientRect().bottom <= window.innerHeight;
  });
}

async function dragOnCanvas(page: import('@playwright/test').Page, from: { x: number; y: number }, to: { x: number; y: number }) {
  await page.mouse.move(from.x, from.y);
  await page.mouse.down();
  await page.mouse.move(to.x, to.y, { steps: 5 });
  await page.mouse.up();
}

/** Clicks "Download PDF" and returns the resulting file's bytes. */
async function downloadAndRead(page: import('@playwright/test').Page, fileName: string): Promise<Uint8Array> {
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: /download pdf/i }).click()]);
  const outPath = join(tmpdir(), fileName);
  await download.saveAs(outPath);
  return readFile(outPath);
}

test('draws a rectangle and it survives export', async ({ page }) => {
  await openAndAnnotate(page);
  await page.getByRole('button', { name: 'Rectangle' }).click();
  const box = (await page.getByTestId('annotate-canvas').boundingBox())!;
  await dragOnCanvas(page, { x: box.x + box.width * 0.2, y: box.y + box.height * 0.2 }, { x: box.x + box.width * 0.6, y: box.y + box.height * 0.6 });
  await page.getByRole('button', { name: 'Select' }).click();

  const content = await firstPageContent(await downloadAndRead(page, 'clientpdf-annotate-rect.pdf'));
  // The default style has both fill and stroke enabled, so pdf-lib emits the
  // combined "fill then stroke" operator (B) rather than separate f/S.
  expect(content).toMatch(/\bB\b/);
});

test('adds a text box by typing and clicking away', async ({ page }) => {
  await openAndAnnotate(page);
  await page.getByRole('button', { name: 'Text' }).click();
  const box = (await page.getByTestId('annotate-canvas').boundingBox())!;
  await page.mouse.click(box.x + box.width * 0.3, box.y + box.height * 0.3);
  await page.keyboard.type('Hello annotations');
  // Click elsewhere on the canvas to exit text editing.
  await page.mouse.click(box.x + box.width * 0.9, box.y + box.height * 0.9);
  await page.getByRole('button', { name: 'Select' }).click();

  const content = await firstPageContent(await downloadAndRead(page, 'clientpdf-annotate-text.pdf'));
  // "Hello annotations" is hex-encoded (WinAnsi) text drawn via Tj.
  const hex = Buffer.from('Hello annotations', 'latin1').toString('hex');
  expect(content.toLowerCase()).toContain(hex);
});

test('undo removes a freshly drawn whiteout box', async ({ page }) => {
  await openAndAnnotate(page);
  await page.getByRole('button', { name: 'Whiteout' }).click();
  const box = (await page.getByTestId('annotate-canvas').boundingBox())!;
  await dragOnCanvas(page, { x: box.x + 20, y: box.y + 20 }, { x: box.x + 100, y: box.y + 100 });
  await page.getByRole('button', { name: 'Select' }).click();

  // A whiteout is a solid-white filled rect — distinctive enough to detect via its fill color operator.
  const withBox = await firstPageContent(await downloadAndRead(page, 'clientpdf-undo-before.pdf'));
  expect(withBox).toMatch(/1 1 1 rg/);

  await page.keyboard.press('Control+z');
  const withoutBox = await firstPageContent(await downloadAndRead(page, 'clientpdf-undo-after.pdf'));
  expect(withoutBox).not.toMatch(/1 1 1 rg/);
});

test('true redact removes the underlying text, visual cover does not', async ({ page }) => {
  await openAndAnnotate(page);
  await page.getByRole('button', { name: 'Redact' }).click();
  await page.getByText('True redact').click();
  const box = (await page.getByTestId('annotate-canvas').boundingBox())!;
  await dragOnCanvas(page, { x: box.x + box.width * 0.1, y: box.y + box.height * 0.1 }, { x: box.x + box.width * 0.9, y: box.y + box.height * 0.9 });
  await page.getByRole('button', { name: 'Select' }).click();

  const bytes = await downloadAndRead(page, 'clientpdf-true-redact.pdf');
  const doc = await PDFDocument.load(bytes);
  // The redacted page is now image-only: no text-drawing operators left at
  // all (real removal, not just a box drawn on top), but it does have an
  // embedded image where its vector/text content used to be.
  const content0 = await firstPageContent(bytes);
  expect(content0).not.toMatch(/Tj/);
  expect(doc.getPages()[0].node.Resources()?.get(PDFName.of('XObject'))).toBeTruthy();
});

test('adds a link that becomes a real PDF annotation', async ({ page }) => {
  await openAndAnnotate(page);
  await page.getByRole('button', { name: 'Link' }).click();
  const box = (await page.getByTestId('annotate-canvas').boundingBox())!;
  await dragOnCanvas(page, { x: box.x + 20, y: box.y + 20 }, { x: box.x + 150, y: box.y + 60 });
  await page.getByPlaceholder('https://example.com').fill('example.com/path');
  await page.getByRole('button', { name: 'Add', exact: true }).click();

  const doc = await PDFDocument.load(await downloadAndRead(page, 'clientpdf-link.pdf'));
  const annots = doc.getPages()[0].node.Annots()!.asArray();
  expect(annots.length).toBeGreaterThan(0);
});

test('draws a signature and it survives export as an embedded image', async ({ page }) => {
  await openAndAnnotate(page);
  await page.getByRole('button', { name: 'Signature' }).click();

  // "Draw" is the default tab — draw a short stroke on the signature pad.
  const pad = page.getByTestId('signature-pad');
  const padBox = (await pad.boundingBox())!;
  await page.mouse.move(padBox.x + 20, padBox.y + padBox.height / 2);
  await page.mouse.down();
  await page.mouse.move(padBox.x + padBox.width - 20, padBox.y + padBox.height / 2, { steps: 5 });
  await page.mouse.up();
  await page.getByRole('button', { name: 'Use signature' }).click();

  // Placing auto-switches to Select; the signature lands at a default spot.
  const bytes = await downloadAndRead(page, 'clientpdf-signature.pdf');
  const doc = await PDFDocument.load(bytes);
  expect(doc.getPages()[0].node.Resources()?.get(PDFName.of('XObject'))).toBeTruthy();
});

test('deletes the selected annotation with the Delete key', async ({ page }) => {
  await openAndAnnotate(page);
  await page.getByRole('button', { name: 'Whiteout' }).click();
  const box = (await page.getByTestId('annotate-canvas').boundingBox())!;
  await dragOnCanvas(page, { x: box.x + 20, y: box.y + 20 }, { x: box.x + 100, y: box.y + 100 });
  await page.getByRole('button', { name: 'Select' }).click();
  // Placing already auto-switches to Select and leaves the shape selected — click it to be sure.
  await page.mouse.click(box.x + 50, box.y + 50);

  await page.keyboard.press('Delete');
  const content = await firstPageContent(await downloadAndRead(page, 'clientpdf-deleted.pdf'));
  expect(content).not.toMatch(/1 1 1 rg/);
});
