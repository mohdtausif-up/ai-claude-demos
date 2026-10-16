import { expect, test } from '@playwright/test';
import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { PDFDocument } from 'pdf-lib';
import { makeFormTestPdf } from './fixtures';

let pdfPath: string;

test.beforeAll(async () => {
  const dir = await mkdtemp(join(tmpdir(), 'clientpdf-e2e-'));
  pdfPath = join(dir, 'form.pdf');
  await writeFile(pdfPath, await makeFormTestPdf());
});

async function downloadAndRead(page: import('@playwright/test').Page, fileName: string): Promise<Uint8Array> {
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: /download pdf/i }).click()]);
  const outPath = join(tmpdir(), fileName);
  await download.saveAs(outPath);
  return readFile(outPath);
}

test('fills an existing text field and the value survives export, still fillable', async ({ page }) => {
  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(pdfPath);
  await page.getByRole('button', { name: 'Fill form' }).click();

  const field = page.getByTestId('form-field');
  await expect(field).toBeVisible();
  await field.fill('Ada Lovelace');
  await field.blur();

  const bytes = await downloadAndRead(page, 'clientpdf-form-filled.pdf');
  const doc = await PDFDocument.load(bytes);
  expect(doc.getForm().getTextField('name').getText()).toBe('Ada Lovelace');
});

test('flattening removes interactivity but keeps the typed value visible', async ({ page }) => {
  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(pdfPath);
  await page.getByRole('button', { name: 'Fill form' }).click();

  const field = page.getByTestId('form-field');
  await field.fill('Grace Hopper');
  await field.blur();
  await page.getByRole('checkbox', { name: 'Flatten on export' }).check();

  const bytes = await downloadAndRead(page, 'clientpdf-form-flattened.pdf');
  const doc = await PDFDocument.load(bytes);
  expect(doc.getForm().getFields()).toHaveLength(0);
});
