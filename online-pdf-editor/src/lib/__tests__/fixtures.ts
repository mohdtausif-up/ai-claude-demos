import { PDFArray, PDFDocument, PDFName, PDFRawStream, StandardFonts, degrees } from 'pdf-lib';
import { pageRefsForSource, type SourceDoc } from '../pageModel';
import { inspectPdf } from '../pdfBuild';

/** Builds a PDF whose page i has the text "Page i+1" and the given sizes. */
export async function makePdf(
  sizes: [number, number][],
  opts: { rotations?: number[]; cropBox?: [number, number, number, number] } = {},
): Promise<Uint8Array> {
  const doc = await PDFDocument.create();
  const font = await doc.embedFont(StandardFonts.Helvetica);
  sizes.forEach(([w, h], i) => {
    const page = doc.addPage([w, h]);
    page.drawText(`Page ${i + 1}`, { x: 20, y: h - 40, size: 18, font });
    if (opts.rotations?.[i]) page.setRotation(degrees(opts.rotations[i]));
    if (opts.cropBox) page.setCropBox(...opts.cropBox);
  });
  return doc.save();
}

export async function makeSource(id: string, bytes: Uint8Array, name = `${id}.pdf`): Promise<SourceDoc> {
  const info = await inspectPdf(bytes, name);
  return { id, name, bytes, pages: info.pages };
}

export async function makeSourceWithRefs(id: string, sizes: [number, number][]) {
  const source = await makeSource(id, await makePdf(sizes));
  return { source, refs: pageRefsForSource(source) };
}

/** A single-page PDF with one field of each supported AcroForm type, for formFields.ts tests. */
export async function makeFormPdf(): Promise<Uint8Array> {
  const doc = await PDFDocument.create();
  const page = doc.addPage([400, 400]);
  const form = doc.getForm();

  const text = form.createTextField('name');
  text.setText('Ada');
  text.addToPage(page, { x: 50, y: 350, width: 150, height: 20 });

  const checkbox = form.createCheckBox('agree');
  checkbox.addToPage(page, { x: 50, y: 300, width: 20, height: 20 });

  const radio = form.createRadioGroup('color');
  radio.addOptionToPage('red', page, { x: 50, y: 250, width: 20, height: 20 });
  radio.addOptionToPage('blue', page, { x: 90, y: 250, width: 20, height: 20 });
  radio.select('red');

  const dropdown = form.createDropdown('size');
  dropdown.setOptions(['S', 'M', 'L']);
  dropdown.select('M');
  dropdown.addToPage(page, { x: 50, y: 200, width: 100, height: 20 });

  return doc.save();
}

/** A PDF whose trailer declares /Encrypt, which pdf-lib treats as encrypted. */
export async function makeEncryptedLookingPdf(): Promise<Uint8Array> {
  const doc = await PDFDocument.create();
  doc.addPage([200, 200]);
  const encryptDict = doc.context.obj({ Filter: PDFName.of('Standard'), V: 2, R: 3 });
  doc.context.trailerInfo.Encrypt = doc.context.register(encryptDict);
  return doc.save({ useObjectStreams: false });
}

export async function readBack(bytes: Uint8Array) {
  const doc = await PDFDocument.load(bytes);
  return doc.getPages().map((p) => ({
    rotation: p.getRotation().angle,
    media: p.getMediaBox(),
    crop: p.getCropBox(),
  }));
}

/** Decoded content-stream strings for each page, in order — for asserting on raw drawing operators. */
export async function pageContents(bytes: Uint8Array): Promise<string[][]> {
  const doc = await PDFDocument.load(bytes);
  const { inflateSync } = await import('node:zlib');
  return doc.getPages().map((page) => {
    const contents = page.node.Contents();
    const refs = contents instanceof PDFArray ? contents.asArray() : [page.node.get(PDFName.of('Contents'))];
    return refs.map((ref) => {
      const stream = doc.context.lookup(ref) as PDFRawStream;
      const raw = Buffer.from(stream.contents);
      return (stream.dict.get(PDFName.of('Filter')) ? inflateSync(raw) : raw).toString('latin1');
    });
  });
}

/** pdf-lib has no text extraction; recover the hex-encoded strings any StandardFonts text was drawn with. */
export async function pageTexts(bytes: Uint8Array): Promise<string[]> {
  return (await pageContents(bytes)).map((streams) =>
    [...streams.join('\n').matchAll(/<([0-9A-Fa-f]+)>\s*Tj/g)].map((m) => Buffer.from(m[1], 'hex').toString('latin1')).join(' '),
  );
}
