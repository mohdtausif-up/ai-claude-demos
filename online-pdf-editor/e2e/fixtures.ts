import { PDFDocument, StandardFonts } from 'pdf-lib';

/** A small multi-page PDF for the e2e suite to drive through the real UI. */
export async function makeTestPdf(pageCount = 5): Promise<Buffer> {
  const doc = await PDFDocument.create();
  const font = await doc.embedFont(StandardFonts.Helvetica);
  for (let i = 0; i < pageCount; i++) {
    const page = doc.addPage([400, 600]);
    page.drawText(`Test page ${i + 1}`, { x: 40, y: 550, size: 24, font });
  }
  return Buffer.from(await doc.save());
}

/** A single-page PDF with one AcroForm text field, for the forms e2e suite. */
export async function makeFormTestPdf(): Promise<Buffer> {
  const doc = await PDFDocument.create();
  const page = doc.addPage([400, 400]);
  const form = doc.getForm();
  const field = form.createTextField('name');
  field.addToPage(page, { x: 50, y: 300, width: 200, height: 24 });
  return Buffer.from(await doc.save());
}
