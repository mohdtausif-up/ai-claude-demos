import { PDFDocument, PDFName } from 'pdf-lib';
import { describe, expect, it } from 'vitest';
import { DEFAULT_DESIGN_SETTINGS, type DesignSettings } from '../design';
import { PdfError } from '../errors';
import { blankPageRef, PAPER_SIZES, type PageRef } from '../pageModel';
import { buildMany, buildPdf, hasPdfHeader, inspectPdf } from '../pdfBuild';
import { makeEncryptedLookingPdf, makePdf, makeSourceWithRefs, pageContents, pageTexts, readBack } from './fixtures';

const bytesOf = (...sources: { id: string; bytes: Uint8Array }[]) =>
  Object.fromEntries(sources.map((s) => [s.id, s.bytes]));

describe('hasPdfHeader / inspectPdf', () => {
  it('reads page sizes and rotations', async () => {
    const bytes = await makePdf([[300, 400], [500, 200]], { rotations: [0, 90] });
    expect(hasPdfHeader(bytes)).toBe(true);
    const info = await inspectPdf(bytes);
    expect(info.pages).toEqual([
      { width: 300, height: 400, rotation: 0, fields: [] },
      { width: 500, height: 200, rotation: 90, fields: [] },
    ]);
  });

  it('uses the crop box as the visible page size', async () => {
    const bytes = await makePdf([[600, 800]], { cropBox: [50, 50, 400, 500] });
    const info = await inspectPdf(bytes);
    expect(info.pages[0]).toMatchObject({ width: 400, height: 500 });
  });

  it('rejects non-PDF input with a clear error', async () => {
    const bytes = new TextEncoder().encode('hello, I am a text file');
    await expect(inspectPdf(bytes, 'notes.txt')).rejects.toMatchObject({ code: 'not-pdf', message: 'notes.txt is not a PDF file.' });
  });

  it('rejects a truncated/corrupt PDF with a clear error', async () => {
    const bytes = new TextEncoder().encode('%PDF-1.7\n1 0 obj << /Type /Catalog /Pages 2 0 R >> garbage');
    const err = await inspectPdf(bytes, 'broken.pdf').catch((e) => e);
    expect(err).toBeInstanceOf(PdfError);
    expect(['corrupt', 'empty']).toContain(err.code);
  });

  it('rejects encrypted PDFs explicitly instead of producing garbage', async () => {
    const bytes = await makeEncryptedLookingPdf();
    await expect(inspectPdf(bytes, 'secret.pdf')).rejects.toMatchObject({ code: 'encrypted' });
  });
});

describe('buildPdf', () => {
  it('preserves order and supports reordering', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[200, 200], [200, 200], [200, 200]]);
    const out = await buildPdf(bytesOf(source), [refs[2], refs[0], refs[1]]);
    expect(await pageTexts(out)).toEqual(['Page 3', 'Page 1', 'Page 2']);
  });

  it('merges pages from several sources', async () => {
    const a = await makeSourceWithRefs('a', [[200, 200], [200, 200]]);
    const b = await makeSourceWithRefs('b', [[300, 300]]);
    const out = await buildPdf(bytesOf(a.source, b.source), [a.refs[0], b.refs[0], a.refs[1]]);
    const pages = await readBack(out);
    expect(pages.map((p) => p.media.width)).toEqual([200, 300, 200]);
    expect(await pageTexts(out)).toEqual(['Page 1', 'Page 1', 'Page 2']);
  });

  it('gives duplicated pages independent copies', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[200, 200]]);
    const rotated: PageRef = { ...refs[0], id: 'dup', rotation: 90 };
    const out = await buildPdf(bytesOf(source), [refs[0], rotated]);
    const pages = await readBack(out);
    expect(pages.map((p) => p.rotation)).toEqual([0, 90]);
  });

  it('adds user rotation on top of the intrinsic /Rotate', async () => {
    const bytes = await makePdf([[200, 300]], { rotations: [90] });
    const { makeSource } = await import('./fixtures');
    const { pageRefsForSource } = await import('../pageModel');
    const source = await makeSource('r', bytes);
    const [ref] = pageRefsForSource(source);
    const out = await buildPdf(bytesOf(source), [{ ...ref, rotation: 270 }]);
    expect((await readBack(out))[0].rotation).toBe(0);
  });

  it('writes blank pages at the requested size', async () => {
    const out = await buildPdf({}, [blankPageRef(PAPER_SIZES.Letter)]);
    const [page] = await readBack(out);
    expect(page.media).toMatchObject({ width: 612, height: 792 });
  });

  it('crops using a top-left based fractional rect', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[400, 800]]);
    const crop = { left: 0.25, top: 0.1, width: 0.5, height: 0.5 };
    const out = await buildPdf(bytesOf(source), [{ ...refs[0], crop }]);
    const [page] = await readBack(out);
    // x = 0.25*400, y = (1 - 0.1 - 0.5) * 800
    expect(page.crop.x).toBeCloseTo(100);
    expect(page.crop.y).toBeCloseTo(320);
    expect(page.crop.width).toBeCloseTo(200);
    expect(page.crop.height).toBeCloseTo(400);
  });

  it('crops relative to an existing crop box', async () => {
    const bytes = await makePdf([[600, 800]], { cropBox: [100, 100, 400, 400] });
    const { makeSource } = await import('./fixtures');
    const { pageRefsForSource } = await import('../pageModel');
    const source = await makeSource('c', bytes);
    const [ref] = pageRefsForSource(source);
    const out = await buildPdf(bytesOf(source), [{ ...ref, crop: { left: 0.5, top: 0, width: 0.5, height: 0.5 } }]);
    const [page] = await readBack(out);
    expect(page.crop).toMatchObject({ x: 300, y: 300, width: 200, height: 200 });
  });

  it('resizes to a target size and sets all page boxes', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[612, 792]]);
    const out = await buildPdf(bytesOf(source), [{ ...refs[0], resize: PAPER_SIZES.A4 }]);
    const [page] = await readBack(out);
    expect(page.media.width).toBeCloseTo(595.28);
    expect(page.media.height).toBeCloseTo(841.89);
    expect(page.crop).toEqual(page.media);
  });

  it('prepends a fit-and-center transform when resizing', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[200, 100]]);
    const out = await buildPdf(bytesOf(source), [{ ...refs[0], resize: { width: 400, height: 400 } }]);
    const [streams] = await pageContents(out);
    // Outer wrapper translates by (0, 100) — scale 2 makes content 400x200, centered vertically.
    expect(streams[0]).toMatch(/1 0 0 1 0 100 cm/);
    expect(streams[1]).toMatch(/2 0 0 2 0 0 cm/);
  });

  it('reports progress up to 1', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[200, 200], [200, 200]]);
    const seen: number[] = [];
    await buildPdf(bytesOf(source), refs, { onProgress: (f) => seen.push(f) });
    expect(seen).toEqual([0.5, 1]);
  });

  it('refuses to build an empty document', async () => {
    await expect(buildPdf({}, [])).rejects.toMatchObject({ code: 'empty' });
  });

  it('errors clearly if a page points at a missing source', async () => {
    const { refs } = await makeSourceWithRefs('a', [[200, 200]]);
    await expect(buildPdf({}, refs)).rejects.toMatchObject({ code: 'build-failed' });
  });
});

describe('design settings (watermark / page numbers / header-footer / background)', () => {
  const withDesign = (patch: Partial<DesignSettings>): DesignSettings => ({ ...DEFAULT_DESIGN_SETTINGS, ...patch });

  it('draws a watermark on top of the page content', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[400, 400]]);
    const design = withDesign({ watermark: { ...DEFAULT_DESIGN_SETTINGS.watermark, enabled: true, text: 'CONFIDENTIAL' } });
    const out = await buildPdf(bytesOf(source), refs, { design });
    expect((await pageTexts(out))[0]).toContain('CONFIDENTIAL');
  });

  it('formats page numbers with {page}/{total} and honors "start at"', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[400, 400], [400, 400]]);
    const design = withDesign({
      pageNumbers: { ...DEFAULT_DESIGN_SETTINGS.pageNumbers, enabled: true, format: 'Page {page} of {total}', startAt: 5 },
    });
    const out = await buildPdf(bytesOf(source), refs, { design });
    const texts = await pageTexts(out);
    expect(texts[0]).toContain('Page 5 of 2');
    expect(texts[1]).toContain('Page 6 of 2');
  });

  it('substitutes {filename} in header/footer text using the output name', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[400, 400]]);
    const design = withDesign({
      headerFooter: { ...DEFAULT_DESIGN_SETTINGS.headerFooter, enabled: true, footerLeft: '{filename}' },
    });
    const out = await buildPdf(bytesOf(source), refs, { design, outputName: 'report.pdf' });
    expect((await pageTexts(out))[0]).toContain('report.pdf');
  });

  it('only applies to pages matched by a custom page selector', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[400, 400], [400, 400], [400, 400]]);
    const design = withDesign({
      watermark: { ...DEFAULT_DESIGN_SETTINGS.watermark, enabled: true, text: 'DRAFT', pages: { mode: 'custom', range: '2' } },
    });
    const out = await buildPdf(bytesOf(source), refs, { design });
    const texts = await pageTexts(out);
    expect(texts[0]).not.toContain('DRAFT');
    expect(texts[1]).toContain('DRAFT');
    expect(texts[2]).not.toContain('DRAFT');
  });

  it('draws the background genuinely behind the page content, not on top of it', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[400, 400]]);
    const design = withDesign({ background: { ...DEFAULT_DESIGN_SETTINGS.background, enabled: true, color: '#00ff00' } });
    const out = await buildPdf(bytesOf(source), refs, { design });

    // The page's own content stream now only contains the background fill and
    // an XObject invocation — the original "Page 1" text moved into that
    // embedded Form XObject's own (separate) content stream, proving it's
    // genuinely layered behind rather than just drawn first in the same stream.
    const [streams] = await pageContents(out);
    const topLevel = streams.join('\n');
    expect(topLevel).toMatch(/0 1 0 rg/);
    // The original "Page 1" text is no longer drawn directly on the page — it
    // now lives inside the embedded Form XObject's own content stream.
    expect(topLevel).not.toMatch(/Tj/);

    const doc = await PDFDocument.load(out);
    const xobjects = doc.getPages()[0].node.Resources()?.get(PDFName.of('XObject'));
    expect(xobjects).toBeTruthy();
  });

  it('leaves the page content stream unchanged when background is disabled', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[400, 400]]);
    const out = await buildPdf(bytesOf(source), refs, { design: DEFAULT_DESIGN_SETTINGS });
    expect(await pageTexts(out)).toEqual(['Page 1']);
  });
});

describe('buildMany', () => {
  it('produces one PDF per output with overall progress', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[200, 200], [200, 200], [200, 200]]);
    const progress: number[] = [];
    const results = await buildMany(
      bytesOf(source),
      [
        { name: 'part-1.pdf', pages: [refs[0], refs[1]] },
        { name: 'part-2.pdf', pages: [refs[2]] },
      ],
      { onProgress: (f) => progress.push(f) },
    );
    expect(results.map((r) => r.name)).toEqual(['part-1.pdf', 'part-2.pdf']);
    expect(await pageTexts(results[0].bytes)).toEqual(['Page 1', 'Page 2']);
    expect(await pageTexts(results[1].bytes)).toEqual(['Page 3']);
    expect(progress.at(-1)).toBe(1);
  });
});
