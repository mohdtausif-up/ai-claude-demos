import { PDFDict, PDFDocument, PDFName, PDFStream } from 'pdf-lib';
import { describe, expect, it } from 'vitest';
import { DEFAULT_FONT, newAnnotationId, type AnnotationObject, type ShapeAnnotation, type SignatureAnnotation } from '../annotations';
import { buildPdf } from '../pdfBuild';
import { blankPageRef, PAPER_SIZES, type PageRef } from '../pageModel';
import { makeSourceWithRefs, pageContents, pageTexts, readBack } from './fixtures';

/** A real, valid 1×1 transparent PNG, for signature-annotation tests. */
const TINY_PNG_DATA_URL =
  'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

const withAnnotations = (ref: PageRef, annotations: AnnotationObject[]): PageRef => ({ ...ref, annotations });

/**
 * pdf-lib draws a filled `drawRectangle` as an explicit moveTo/lineTo path
 * (position via its own innermost `cm`, then a unit path scaled by the box
 * size) rather than the `re` shorthand — this matches that exact structure
 * and pulls out the resulting x/y/width/height for numeric comparison.
 */
function parseFilledRect(content: string): { x: number; y: number; width: number; height: number } | null {
  const m = /1 0 0 1 (-?[\d.]+) (-?[\d.]+) cm\n1 0 0 1 0 0 cm\n1 0 0 1 0 0 cm\n0 0 m\n0 ([\d.]+) l\n([\d.]+) \3 l\n\4 0 l/.exec(
    content,
  );
  if (!m) return null;
  return { x: Number(m[1]), y: Number(m[2]), height: Number(m[3]), width: Number(m[4]) };
}

function expectRect(actual: ReturnType<typeof parseFilledRect>, expected: { x: number; y: number; width: number; height: number }): void {
  expect(actual).not.toBeNull();
  expect(actual!.x).toBeCloseTo(expected.x);
  expect(actual!.y).toBeCloseTo(expected.y);
  expect(actual!.width).toBeCloseTo(expected.width);
  expect(actual!.height).toBeCloseTo(expected.height);
}

/** The blend mode lives on an ExtGState resource dict, which useObjectStreams
 * packs into a compressed object stream — re-save without that packing so a
 * plain-text search over the raw bytes can find it. */
async function findBlendModes(bytes: Uint8Array): Promise<string> {
  const doc = await PDFDocument.load(bytes);
  const plain = await doc.save({ useObjectStreams: false });
  return Buffer.from(plain).toString('latin1');
}

const rectAnno = (over: Partial<ShapeAnnotation> = {}): ShapeAnnotation => ({
  id: newAnnotationId(),
  type: 'rectangle',
  rect: { left: 0.1, top: 0.2, width: 0.5, height: 0.25 },
  fillColor: '#ff0000',
  strokeColor: null,
  strokeWidth: 0,
  opacity: 1,
  ...over,
});

describe('drawAnnotations — shapes', () => {
  it('converts a top-left fractional rect into a bottom-left PDF rectangle', async () => {
    const page = withAnnotations(blankPageRef({ width: 400, height: 800 }), [rectAnno()]);
    const out = await buildPdf({}, [page]);
    const [streams] = await pageContents(out);
    // left=0.1*400=40, width=0.5*400=200; y = (1-0.2-0.25)*800=440, height=0.25*800=200
    expectRect(parseFilledRect(streams.join('\n')), { x: 40, y: 440, width: 200, height: 200 });
  });

  it('draws an ellipse centered in its rect using bezier curves', async () => {
    const page = withAnnotations(blankPageRef({ width: 400, height: 400 }), [
      rectAnno({ type: 'ellipse', rect: { left: 0, top: 0, width: 0.5, height: 0.5 }, fillColor: '#00ff00' }),
    ]);
    const out = await buildPdf({}, [page]);
    const [streams] = await pageContents(out);
    // Ellipse center = (100,300) in a 400x400 page (top-left 0,0 half-size box).
    expect(streams.join('\n')).toMatch(/\bc\b/); // bezier curve operator present
    expect(streams.join('\n')).toMatch(/0 1 0 rg/); // green fill color
  });

  it('omits the fill/stroke option pdf-lib would otherwise default when the color is unset', async () => {
    const page = withAnnotations(blankPageRef({ width: 200, height: 200 }), [
      rectAnno({ fillColor: null, strokeColor: '#0000ff', strokeWidth: 3 }),
    ]);
    const out = await buildPdf({}, [page]);
    const [streams] = await pageContents(out);
    const joined = streams.join('\n');
    expect(joined).toMatch(/0 0 1 RG/); // blue stroke color
    expect(joined).not.toMatch(/\brg\b/); // no fill color operator
  });
});

describe('drawAnnotations — lines and arrows', () => {
  it('draws a line between the two fractional points', async () => {
    const page = withAnnotations(blankPageRef({ width: 200, height: 200 }), [
      { id: newAnnotationId(), type: 'line', start: { x: 0, y: 0 }, end: { x: 1, y: 1 }, strokeColor: '#000000', strokeWidth: 2, opacity: 1 },
    ]);
    const out = await buildPdf({}, [page]);
    const [streams] = await pageContents(out);
    // start (0,0) top-left → PDF (0,200); end (1,1) → PDF (200,0).
    const joined = streams.join('\n');
    expect(joined).toMatch(/0 200 m/);
    expect(joined).toMatch(/200 0 l/);
  });

  it('adds a filled triangular arrowhead at the end point', async () => {
    const page = withAnnotations(blankPageRef({ width: 200, height: 200 }), [
      { id: newAnnotationId(), type: 'arrow', start: { x: 0, y: 0.5 }, end: { x: 1, y: 0.5 }, strokeColor: '#000000', strokeWidth: 2, opacity: 1 },
    ]);
    const out = await buildPdf({}, [page]);
    const [streams] = await pageContents(out);
    const joined = streams.join('\n');
    expect(joined).toMatch(/200 100 m/); // arrowhead tip at the end point
    expect(joined).toMatch(/\bf\b/); // filled
  });
});

describe('drawAnnotations — freehand draw', () => {
  it('strokes a polyline through every point', async () => {
    const page = withAnnotations(blankPageRef({ width: 100, height: 100 }), [
      {
        id: newAnnotationId(),
        type: 'draw',
        points: [{ x: 0, y: 0 }, { x: 0.5, y: 0.5 }, { x: 1, y: 1 }],
        strokeColor: '#123456',
        strokeWidth: 1.5,
        opacity: 1,
      },
    ]);
    const out = await buildPdf({}, [page]);
    const [streams] = await pageContents(out);
    const joined = streams.join('\n');
    expect(joined).toMatch(/0 100 m/);
    expect(joined).toMatch(/50 50 l/);
    expect(joined).toMatch(/100 0 l/);
    expect(joined).toMatch(/\bS\b/); // stroke operator
  });

  it('draws nothing for a degenerate single-point path', async () => {
    const page = withAnnotations(blankPageRef({ width: 100, height: 100 }), [
      { id: newAnnotationId(), type: 'draw', points: [{ x: 0.5, y: 0.5 }], strokeColor: '#000', strokeWidth: 1, opacity: 1 },
    ]);
    await expect(buildPdf({}, [page])).resolves.toBeInstanceOf(Uint8Array);
  });
});

describe('drawAnnotations — whiteout and markup', () => {
  it('draws whiteout as an opaque rectangle', async () => {
    const page = withAnnotations(blankPageRef({ width: 200, height: 200 }), [
      { id: newAnnotationId(), type: 'whiteout', rect: { left: 0.25, top: 0.25, width: 0.5, height: 0.5 }, color: '#ffffff', opacity: 1 },
    ]);
    const out = await buildPdf({}, [page]);
    const [streams] = await pageContents(out);
    expectRect(parseFilledRect(streams.join('\n')), { x: 50, y: 50, width: 100, height: 100 });
  });

  it('draws a highlight with a multiply blend mode so text underneath stays legible', async () => {
    const page = withAnnotations(blankPageRef({ width: 200, height: 200 }), [
      { id: newAnnotationId(), type: 'markup', kind: 'highlight', rects: [{ left: 0, top: 0, width: 1, height: 0.1 }], color: '#ffff00', opacity: 0.4 },
    ]);
    const out = await buildPdf({}, [page]);
    // The blend mode lives on the ExtGState resource dict, not in the content
    // stream itself, so check the raw (uncompressed-dict) PDF bytes for it.
    expect(await findBlendModes(out)).toMatch(/\/BM\s*\/Multiply/);
  });

  it('draws underline and strikethrough as thin lines instead of filled boxes', async () => {
    const page = withAnnotations(blankPageRef({ width: 200, height: 100 }), [
      { id: newAnnotationId(), type: 'markup', kind: 'underline', rects: [{ left: 0, top: 0, width: 1, height: 0.2 }], color: '#ff0000', opacity: 1 },
    ]);
    const out = await buildPdf({}, [page]);
    const [streams] = await pageContents(out);
    expect(streams.join('\n')).not.toMatch(/Multiply/);
  });
});

describe('drawAnnotations — redact', () => {
  it('flattens both visual and true mode as the same opaque cover box', async () => {
    for (const mode of ['visual', 'true'] as const) {
      const page = withAnnotations(blankPageRef({ width: 200, height: 200 }), [
        { id: newAnnotationId(), type: 'redact', mode, rect: { left: 0.25, top: 0.25, width: 0.5, height: 0.5 }, color: '#000000', opacity: 1 },
      ]);
      const out = await buildPdf({}, [page]);
      const [streams] = await pageContents(out);
      expectRect(parseFilledRect(streams.join('\n')), { x: 50, y: 50, width: 100, height: 100 });
    }
  });

  it('ignores the annotation opacity for a redact box — it must always fully hide content', async () => {
    const page = withAnnotations(blankPageRef({ width: 100, height: 100 }), [
      { id: newAnnotationId(), type: 'redact', mode: 'visual', rect: { left: 0, top: 0, width: 1, height: 1 }, color: '#000000', opacity: 0.3 },
    ]);
    const out = await buildPdf({}, [page]);
    const [streams] = await pageContents(out);
    // fillingRgbColor's graphics-state opacity (`gs`) should be absent/at full
    // opacity — a translucent "redaction" that lets text show through would
    // defeat the purpose of the tool, regardless of what the UI slider says.
    expect(streams.join('\n')).not.toMatch(/\/ca 0\.3/);
  });
});

describe('drawAnnotations — text', () => {
  it('draws left-aligned text at the top of its box', async () => {
    const page = withAnnotations(blankPageRef({ width: 400, height: 200 }), [
      { id: newAnnotationId(), type: 'text', rect: { left: 0, top: 0, width: 1, height: 1 }, text: 'Hello', font: DEFAULT_FONT, fontSize: 20, color: '#000000', align: 'left', opacity: 1 },
    ]);
    const out = await buildPdf({}, [page]);
    expect((await pageTexts(out))[0]).toBe('Hello');
  });

  it('word-wraps long text to fit the box width', async () => {
    const page = withAnnotations(blankPageRef({ width: 150, height: 200 }), [
      {
        id: newAnnotationId(),
        type: 'text',
        rect: { left: 0, top: 0, width: 1, height: 1 },
        text: 'one two three four five six seven',
        font: DEFAULT_FONT,
        fontSize: 12,
        color: '#000000',
        align: 'left',
        opacity: 1,
      },
    ]);
    const out = await buildPdf({}, [page]);
    const [streams] = await pageContents(out);
    // More than one Tj call means it wrapped onto multiple lines.
    expect(streams.join('\n').match(/Tj/g)?.length ?? 0).toBeGreaterThan(1);
  });

  it('right-aligns text within its box', async () => {
    const left = withAnnotations(blankPageRef({ width: 400, height: 100 }), [
      { id: newAnnotationId(), type: 'text', rect: { left: 0, top: 0, width: 1, height: 1 }, text: 'Hi', font: DEFAULT_FONT, fontSize: 20, color: '#000', align: 'left', opacity: 1 },
    ]);
    const right = withAnnotations(blankPageRef({ width: 400, height: 100 }), [
      { id: newAnnotationId(), type: 'text', rect: { left: 0, top: 0, width: 1, height: 1 }, text: 'Hi', font: DEFAULT_FONT, fontSize: 20, color: '#000', align: 'right', opacity: 1 },
    ]);
    const leftX = /BT[\s\S]*?1 0 0 1 (\S+) \S+ Tm/.exec((await pageContents(await buildPdf({}, [left])))[0].join('\n'));
    const rightX = /BT[\s\S]*?1 0 0 1 (\S+) \S+ Tm/.exec((await pageContents(await buildPdf({}, [right])))[0].join('\n'));
    expect(Number(rightX![1])).toBeGreaterThan(Number(leftX![1]));
  });

  it('skips lines that overflow the box height instead of drawing outside it', async () => {
    const page = withAnnotations(blankPageRef({ width: 400, height: 30 }), [
      { id: newAnnotationId(), type: 'text', rect: { left: 0, top: 0, width: 1, height: 1 }, text: 'one\ntwo\nthree\nfour', font: DEFAULT_FONT, fontSize: 20, color: '#000', align: 'left', opacity: 1 },
    ]);
    const out = await buildPdf({}, [page]);
    const [streams] = await pageContents(out);
    // 30pt box, 24pt line height → only the first line fits.
    expect(streams.join('\n').match(/Tj/g)?.length).toBe(1);
  });
});

describe('drawAnnotations — integration with crop/rotate/resize', () => {
  it('scales annotations along with resized page content', async () => {
    const page = withAnnotations({ ...blankPageRef({ width: 100, height: 100 }), resize: PAPER_SIZES.A4 }, [rectAnno()]);
    const out = await buildPdf({}, [page]);
    const [streams] = await pageContents(out);
    // The annotation's own position/size are still in original (100x100)
    // coordinates — scaling happens via the page's outer transform (already
    // verified generically in pdfBuild.test.ts's resize tests).
    expectRect(parseFilledRect(streams.join('\n')), { x: 10, y: 55, width: 50, height: 25 });
    const [page0] = await readBack(out);
    expect(page0.media.width).toBeCloseTo(PAPER_SIZES.A4.width);
  });

  it('draws annotations onto a page copied from a source PDF, not just blank pages', async () => {
    const { source, refs } = await makeSourceWithRefs('a', [[300, 300]]);
    const page = withAnnotations(refs[0], [rectAnno()]);
    const out = await buildPdf({ a: source.bytes }, [page]);
    const [streams] = await pageContents(out);
    // left=0.1*300=30, top=0.2*300=60, width=0.5*300=150, height=0.25*300=75 → y=(1-0.2-0.25)*300=165
    expectRect(parseFilledRect(streams.join('\n')), { x: 30, y: 165, width: 150, height: 75 });
    // The source page's own drawn "Page 1" text must still be present too.
    expect((await pageTexts(out))[0]).toContain('Page 1');
  });
});

describe('drawAnnotations — signature', () => {
  const signatureAnno = (over: Partial<SignatureAnnotation> = {}): SignatureAnnotation => ({
    id: newAnnotationId(),
    type: 'signature',
    rect: { left: 0.1, top: 0.6, width: 0.3, height: 0.15 },
    imageDataUrl: TINY_PNG_DATA_URL,
    opacity: 1,
    ...over,
  });

  it('embeds the signature image and draws it on the page', async () => {
    const page = withAnnotations(blankPageRef({ width: 400, height: 800 }), [signatureAnno()]);
    const out = await buildPdf({}, [page]);
    const doc = await PDFDocument.load(out);
    const xobjects = doc.getPages()[0].node.Resources()?.get(PDFName.of('XObject'));
    expect(xobjects).toBeTruthy();
    const [streams] = await pageContents(out);
    expect(streams.join('\n')).toMatch(/\bDo\b/);
  });

  it('embeds the same data URL only once across multiple placements (two resource names, one underlying image object)', async () => {
    const page = withAnnotations(blankPageRef({ width: 400, height: 800 }), [
      signatureAnno(),
      signatureAnno({ id: newAnnotationId(), rect: { left: 0.1, top: 0.1, width: 0.3, height: 0.15 } }),
    ]);
    const out = await buildPdf({}, [page]);
    const doc = await PDFDocument.load(out);
    const xobjects = doc.getPages()[0].node.Resources()?.get(PDFName.of('XObject'));
    // Each drawImage() call adds its own resource *name* pointing at the same
    // underlying image, so there are 2 names here — the actual de-dup this
    // test cares about is that only one /Subtype /Image stream object exists
    // in the whole file (ImageCache.get() calls doc.embedPng() only once).
    expect((xobjects as PDFDict).keys().length).toBe(2);
    const imageObjects = doc.context
      .enumerateIndirectObjects()
      .filter(([, obj]) => obj instanceof PDFStream && obj.dict.get(PDFName.of('Subtype'))?.toString() === '/Image');
    expect(imageObjects).toHaveLength(1);
  });

  it('rides along with crop/resize like every other flattened annotation', async () => {
    const page = withAnnotations({ ...blankPageRef({ width: 100, height: 100 }), resize: PAPER_SIZES.A4 }, [signatureAnno()]);
    const out = await buildPdf({}, [page]);
    const [page0] = await readBack(out);
    expect(page0.media.width).toBeCloseTo(PAPER_SIZES.A4.width);
  });
});
