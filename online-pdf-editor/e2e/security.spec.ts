import { expect, test } from '@playwright/test';
import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { PDFDocument } from 'pdf-lib';
import { makeTestPdf } from './fixtures';

let pdfPath: string;

test.beforeAll(async () => {
  const dir = await mkdtemp(join(tmpdir(), 'clientpdf-e2e-'));
  pdfPath = join(dir, 'sample.pdf');
  await writeFile(pdfPath, await makeTestPdf(2));
});

async function downloadAndRead(page: import('@playwright/test').Page, fileName: string): Promise<Uint8Array> {
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: /download pdf/i }).click()]);
  const outPath = join(tmpdir(), fileName);
  await download.saveAs(outPath);
  return readFile(outPath);
}

test('protects a PDF with a password via the real qpdf WASM build, producing a file pdf-lib reports as encrypted', async ({ page }) => {
  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(pdfPath);
  await page.getByRole('button', { name: 'Protect…' }).click();

  await page.getByLabel('Password to open the file').fill('sesame123');
  await page.getByRole('button', { name: 'Protect & download' }).click();

  const [download] = await Promise.all([page.waitForEvent('download'), Promise.resolve()]);
  const outPath = join(tmpdir(), 'clientpdf-protected.pdf');
  await download.saveAs(outPath);
  const bytes = await readFile(outPath);

  // pdf-lib refuses to load it without ignoreEncryption, proving it's genuinely encrypted output.
  await expect(PDFDocument.load(bytes)).rejects.toThrow();
  const doc = await PDFDocument.load(bytes, { ignoreEncryption: true });
  expect(doc.isEncrypted).toBe(true);
});

test('round-trips: protect with a password, then re-open with that password and download an unprotected copy', async ({ page }) => {
  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(pdfPath);
  await page.getByRole('button', { name: 'Protect…' }).click();
  await page.getByLabel('Password to open the file').fill('round-trip-pw');
  const [download1] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Protect & download' }).click()]);
  const protectedPath = join(tmpdir(), 'clientpdf-roundtrip-protected.pdf');
  await download1.saveAs(protectedPath);

  // Fresh page load: open the just-protected file. The app should detect it's
  // encrypted and prompt for a password rather than erroring out.
  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(protectedPath);
  await expect(page.getByText(/is password-protected/i)).toBeVisible();

  // Wrong password first: the dialog should stay open with an error, not crash.
  await page.getByPlaceholder('Password').fill('wrong-password');
  await page.getByRole('button', { name: 'Unlock' }).click();
  await expect(page.getByText(/incorrect password/i)).toBeVisible();

  await page.getByPlaceholder('Password').fill('round-trip-pw');
  await page.getByRole('button', { name: 'Unlock' }).click();
  await expect(page.getByText('Page 1 of 2')).toBeVisible();

  // A plain Download now produces an unprotected copy of the same content.
  const bytes = await downloadAndRead(page, 'clientpdf-roundtrip-unprotected.pdf');
  const doc = await PDFDocument.load(bytes); // throws if still encrypted
  expect(doc.isEncrypted).toBe(false);
  expect(doc.getPages()).toHaveLength(2);
});
