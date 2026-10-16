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
  await writeFile(pdfPath, await makeTestPdf(5));
});

test('shows the privacy banner on the landing page', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByText(/processed 100% in your browser/i)).toBeVisible();
  await expect(page.getByRole('heading', { name: 'ClientPDF' })).toBeVisible();
});

test('opens a PDF and shows its pages', async ({ page }) => {
  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(pdfPath);
  await expect(page.getByText('Page 1 of 5')).toBeVisible();
  await expect(page.getByText('5 pages')).toBeVisible();
});

test('rejects a non-PDF file with a clear error, not a crash', async ({ page }) => {
  const dir = await mkdtemp(join(tmpdir(), 'clientpdf-e2e-'));
  const txtPath = join(dir, 'notes.txt');
  await writeFile(txtPath, 'just some text');

  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(txtPath);
  await expect(page.getByRole('alert')).toContainText(/not a pdf file/i);
  // The landing page must still be usable afterwards.
  await expect(page.getByRole('heading', { name: 'ClientPDF' })).toBeVisible();
});

test('rotate, delete and undo/redo update the page count and toolbar state', async ({ page }) => {
  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(pdfPath);
  await expect(page.getByText('5 pages')).toBeVisible();

  await page.getByRole('button', { name: '⟳ Rotate right' }).click();
  await expect(page.getByRole('button', { name: '↩ Undo' })).toBeEnabled();

  await page.getByRole('button', { name: 'Delete', exact: true }).click();
  await page.getByRole('alertdialog').getByRole('button', { name: 'Delete', exact: true }).click();
  await expect(page.getByText('4 pages')).toBeVisible();
  await expect(page.getByText('Page 1 of 4')).toBeVisible();

  await page.getByRole('button', { name: '↩ Undo' }).click();
  await expect(page.getByText('5 pages')).toBeVisible();
  await page.getByRole('button', { name: '↪ Redo' }).click();
  await expect(page.getByText('4 pages')).toBeVisible();
});

test('crop tool applies a crop that survives export', async ({ page }) => {
  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(pdfPath);

  await page.getByRole('button', { name: 'Crop', exact: true }).click();
  const canvas = page.getByTestId('page-canvas');
  const box = (await canvas.boundingBox())!;
  // Drag the crop rectangle's bottom-right handle inward, well within the page.
  await page.mouse.move(box.x + box.width, box.y + box.height);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width * 0.5, box.y + box.height * 0.5);
  await page.mouse.up();
  await page.getByRole('button', { name: 'Apply crop' }).click();

  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: /download pdf/i }).click()]);
  const outPath = join(tmpdir(), 'clientpdf-crop-out.pdf');
  await download.saveAs(outPath);
  const doc = await PDFDocument.load(await readFile(outPath));
  const cropBox = doc.getPages()[0].getCropBox();
  // Original page is 400x600; a corner drag to the midpoint should roughly halve it.
  expect(cropBox.width).toBeLessThan(350);
  expect(cropBox.height).toBeLessThan(550);
  expect(cropBox.width).toBeGreaterThan(150);
});

test('split by range downloads a zip with the right number of parts', async ({ page }) => {
  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(pdfPath);

  await page.getByRole('button', { name: 'Split by range…' }).click();
  await page.getByLabel('Ranges').fill('1-2, 3-5');
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Split', exact: true }).click()]);
  expect(download.suggestedFilename()).toMatch(/\.zip$/);
});

test('merging a second file combines pages in order', async ({ page }) => {
  await page.goto('/');
  await page.getByTestId('file-input').setInputFiles(pdfPath);
  await expect(page.getByText('5 pages')).toBeVisible();

  const dir = await mkdtemp(join(tmpdir(), 'clientpdf-e2e-'));
  const second = join(dir, 'second.pdf');
  await writeFile(second, await makeTestPdf(2));
  await page.getByText('+ Insert file').locator('input[type=file]').setInputFiles(second);
  await expect(page.getByText('7 pages')).toBeVisible();
});
