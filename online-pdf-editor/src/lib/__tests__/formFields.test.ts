import { PDFName, PDFDocument, PDFRawStream } from 'pdf-lib';
import { describe, expect, it } from 'vitest';
import { DEFAULT_DESIGN_SETTINGS } from '../design';
import { extractFormFields } from '../formFields';
import { pageRefsForSource } from '../pageModel';
import { buildPdf, inspectPdf } from '../pdfBuild';
import { makeFormPdf, makeSource } from './fixtures';

describe('extractFormFields', () => {
  it('reads name, type, value and options for every supported field type', async () => {
    const bytes = await makeFormPdf();
    const doc = await PDFDocument.load(bytes);
    const [fields] = extractFormFields(doc, doc.getPages());

    const byName = Object.fromEntries(fields.map((f) => [f.name, f]));
    expect(byName.name).toMatchObject({ type: 'text', value: 'Ada' });
    expect(byName.agree).toMatchObject({ type: 'checkbox', value: false });
    expect(byName.size).toMatchObject({ type: 'dropdown', value: 'M', options: ['S', 'M', 'L'] });

    // The radio group has two widgets (one per option), each its own entry sharing the field name.
    const radios = fields.filter((f) => f.name === 'color');
    expect(radios).toHaveLength(2);
    expect(radios.every((r) => r.type === 'radio' && r.value === 'red')).toBe(true);
    expect(radios.map((r) => r.widgetOptionValue).sort()).toEqual(['blue', 'red']);
  });

  it('places each field at the correct fractional rect within the page', async () => {
    const bytes = await makeFormPdf();
    const doc = await PDFDocument.load(bytes);
    const [fields] = extractFormFields(doc, doc.getPages());
    const name = fields.find((f) => f.name === 'name')!;
    // x=50/400=0.125, width=150/400=0.375; top = 1 - 20/400 - 350/400 = 0.075
    expect(name.rect.left).toBeCloseTo(0.125);
    expect(name.rect.width).toBeCloseTo(0.375);
    expect(name.rect.top).toBeCloseTo(0.075);
    expect(name.rect.height).toBeCloseTo(0.05);
  });

  it('returns an empty field list for a PDF with no AcroForm', async () => {
    const plain = await PDFDocument.create();
    plain.addPage([200, 200]);
    const doc = await PDFDocument.load(await plain.save());
    const [fields] = extractFormFields(doc, doc.getPages());
    expect(fields).toEqual([]);
  });
});

describe('inspectPdf — form fields', () => {
  it('surfaces extracted fields on SourcePageInfo', async () => {
    const bytes = await makeFormPdf();
    const result = await inspectPdf(bytes);
    const names = result.pages[0].fields.map((f) => f.name);
    expect(names).toContain('name');
    expect(names).toContain('agree');
  });
});

describe('buildPdf — form values and flattening', () => {
  it('applies text/checkbox/radio/dropdown values onto the output form', async () => {
    const bytes = await makeFormPdf();
    const source = await makeSource('f', bytes);
    const refs = pageRefsForSource(source);
    const out = await buildPdf({ f: bytes }, refs, {
      formValues: { name: 'Grace', agree: true, color: 'blue', size: 'L' },
    });
    const doc = await PDFDocument.load(out);
    const form = doc.getForm();
    expect(form.getTextField('name').getText()).toBe('Grace');
    expect(form.getCheckBox('agree').isChecked()).toBe(true);
    expect(form.getRadioGroup('color').getSelected()).toBe('blue');
    expect(form.getDropdown('size').getSelected()).toEqual(['L']);
  });

  it('leaves fields untouched when no formValues are given', async () => {
    const bytes = await makeFormPdf();
    const source = await makeSource('f', bytes);
    const refs = pageRefsForSource(source);
    const out = await buildPdf({ f: bytes }, refs);
    const doc = await PDFDocument.load(out);
    expect(doc.getForm().getTextField('name').getText()).toBe('Ada');
  });

  it('flattens the form (removing interactivity) when flattenForms is set', async () => {
    const bytes = await makeFormPdf();
    const source = await makeSource('f', bytes);
    const refs = pageRefsForSource(source);
    const out = await buildPdf({ f: bytes }, refs, { formValues: { name: 'Grace' }, flattenForms: true });
    const doc = await PDFDocument.load(out);
    expect(doc.getForm().getFields()).toHaveLength(0);
    // The value is now baked in as a real appearance, drawn via Tj inside an
    // embedded Form XObject (flatten() draws appearances into the field's
    // own XObject, not directly into the page's content stream) — decode
    // every stream object in the file, not just the page's own, to find it.
    const { inflateSync } = await import('node:zlib');
    const hex = Buffer.from('Grace', 'latin1').toString('hex');
    const allStreamText = doc.context
      .enumerateIndirectObjects()
      .filter(([, obj]) => obj instanceof PDFRawStream)
      .map(([, obj]) => {
        const stream = obj as PDFRawStream;
        const raw = Buffer.from(stream.contents);
        return (stream.dict.get(PDFName.of('Filter')) ? inflateSync(raw) : raw).toString('latin1');
      })
      .join('\n');
    expect(allStreamText.toLowerCase()).toContain(hex);
  });

  it('keeps form fields fillable on a page that also gets a background (background re-embeds content as an XObject, dropping /Annots unless carried over)', async () => {
    const bytes = await makeFormPdf();
    const source = await makeSource('f', bytes);
    const refs = pageRefsForSource(source);
    const design = { ...DEFAULT_DESIGN_SETTINGS, background: { ...DEFAULT_DESIGN_SETTINGS.background, enabled: true, color: '#eeeeee' } };
    const out = await buildPdf({ f: bytes }, refs, { design, formValues: { name: 'Grace' } });
    const doc = await PDFDocument.load(out);
    expect(doc.getForm().getTextField('name').getText()).toBe('Grace');
  });

  it('applies a value to every copy when the same form-field page is repeated in the output', async () => {
    const bytes = await makeFormPdf();
    const source = await makeSource('f', bytes);
    const [onePage] = pageRefsForSource(source);
    // Two independent occurrences of the same source page, as e.g. extracting "1, 1" would produce.
    const refs = [onePage, { ...onePage, id: 'dup' }];
    const out = await buildPdf({ f: bytes }, refs, { formValues: { name: 'Grace' } });
    const doc = await PDFDocument.load(out);
    const values = doc
      .getForm()
      .getFields()
      .filter((f) => f.getName() === 'name')
      .map((f) => (f as unknown as { getText(): string }).getText());
    expect(values).toEqual(['Grace', 'Grace']);
  });
});
