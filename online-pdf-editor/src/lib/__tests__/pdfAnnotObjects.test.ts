import { PDFDict, PDFDocument, PDFName, PDFString } from 'pdf-lib';
import { describe, expect, it } from 'vitest';
import { newAnnotationId, type AnnotationObject } from '../annotations';
import { blankPageRef, type PageRef } from '../pageModel';
import { buildPdf } from '../pdfBuild';
import { readBack } from './fixtures';

const withAnnotations = (ref: PageRef, annotations: AnnotationObject[]): PageRef => ({ ...ref, annotations });

async function getAnnots(bytes: Uint8Array) {
  const doc = await PDFDocument.load(bytes);
  const page = doc.getPages()[0];
  const annots = page.node.Annots();
  if (!annots) return [];
  return annots.asArray().map((ref) => doc.context.lookup(ref) as PDFDict);
}

describe('link annotations', () => {
  it('adds a real Link annotation with a URI action, not flattened content', async () => {
    const page = withAnnotations(blankPageRef({ width: 200, height: 200 }), [
      { id: newAnnotationId(), type: 'link', rect: { left: 0.25, top: 0.25, width: 0.5, height: 0.1 }, opacity: 1, url: 'https://example.com/' },
    ]);
    const out = await buildPdf({}, [page]);
    const [annot] = await getAnnots(out);
    expect(annot.get(PDFName.of('Subtype'))).toEqual(PDFName.of('Link'));
    const action = annot.get(PDFName.of('A')) as PDFDict;
    expect(action.get(PDFName.of('S'))).toEqual(PDFName.of('URI'));
    expect((action.get(PDFName.of('URI')) as PDFString).asString()).toBe('https://example.com/');

    const rect = annot.get(PDFName.of('Rect'));
    const nums = (rect as unknown as { asArray(): { asNumber(): number }[] }).asArray().map((n) => n.asNumber());
    // left=0.25*200=50, width=0.5*200=100 → [50, ?, 150, ?]; top=0.25 → y=(1-0.25-0.1)*200=130, height=20 → [?,130,?,150]
    expect(nums[0]).toBeCloseTo(50);
    expect(nums[2]).toBeCloseTo(150);
    expect(nums[1]).toBeCloseTo(130);
    expect(nums[3]).toBeCloseTo(150);
  });

  it('leaves the link rect unmoved by crop (coordinates, not the visible window, are what a crop changes)', async () => {
    const page = withAnnotations(blankPageRef({ width: 200, height: 200 }), [
      { id: newAnnotationId(), type: 'link', rect: { left: 0.25, top: 0.25, width: 0.5, height: 0.1 }, opacity: 1, url: 'https://a.test' },
    ]);
    const cropped = { ...page, crop: { left: 0, top: 0, width: 0.5, height: 0.5 } };
    const [withoutCrop, withCrop] = await Promise.all([buildPdf({}, [page]), buildPdf({}, [cropped])]);
    const a1 = (await getAnnots(withoutCrop))[0].get(PDFName.of('Rect'));
    const a2 = (await getAnnots(withCrop))[0].get(PDFName.of('Rect'));
    expect(a1!.toString()).toBe(a2!.toString());
  });

  it('scales the link rect to match a resized page', async () => {
    const page = withAnnotations({ ...blankPageRef({ width: 100, height: 100 }), resize: { width: 200, height: 200 } }, [
      { id: newAnnotationId(), type: 'link', rect: { left: 0, top: 0, width: 1, height: 1 }, opacity: 1, url: 'https://a.test' },
    ]);
    const out = await buildPdf({}, [page]);
    const [annot] = await getAnnots(out);
    const nums = (annot.get(PDFName.of('Rect')) as unknown as { asArray(): { asNumber(): number }[] }).asArray().map((n) => n.asNumber());
    // 100x100 page resized to fill a 200x200 target with no letterboxing → full rect scales 1:1 to [0,0,200,200].
    expect(nums).toEqual([0, 0, 200, 200]);
  });

  it('positions the link rect correctly when both crop and resize apply', async () => {
    // The crop window (200x400 at offset 100,320) has the same aspect ratio as
    // the resize target (300x600), so a link covering exactly the crop window
    // should end up covering the entire resized output page.
    const page = withAnnotations(
      {
        ...blankPageRef({ width: 400, height: 800 }),
        crop: { left: 0.25, top: 0.1, width: 0.5, height: 0.5 },
        resize: { width: 300, height: 600 },
      },
      [{ id: newAnnotationId(), type: 'link', rect: { left: 0.25, top: 0.1, width: 0.5, height: 0.5 }, opacity: 1, url: 'https://a.test' }],
    );
    const out = await buildPdf({}, [page]);
    const [annot] = await getAnnots(out);
    const nums = (annot.get(PDFName.of('Rect')) as unknown as { asArray(): { asNumber(): number }[] }).asArray().map((n) => n.asNumber());
    expect(nums[0]).toBeCloseTo(0);
    expect(nums[1]).toBeCloseTo(0);
    expect(nums[2]).toBeCloseTo(300);
    expect(nums[3]).toBeCloseTo(600);
  });
});

describe('note annotations', () => {
  it('adds a real Text annotation with the note body as its Contents', async () => {
    const page = withAnnotations(blankPageRef({ width: 200, height: 200 }), [
      { id: newAnnotationId(), type: 'note', at: { x: 0.5, y: 0.5 }, text: 'Remember to check this', color: '#ffcc00', opacity: 1 },
    ]);
    const out = await buildPdf({}, [page]);
    const [annot] = await getAnnots(out);
    expect(annot.get(PDFName.of('Subtype'))).toEqual(PDFName.of('Text'));
    // Contents is a hex-encoded UTF-16BE text string (with a leading BOM), per the PDF spec.
    const hex = annot.lookup(PDFName.of('Contents'))!.toString().replace(/[<>]/g, '');
    const buf = Buffer.from(hex, 'hex');
    let decoded = '';
    for (let i = 2; i < buf.length; i += 2) decoded += String.fromCharCode(buf.readUInt16BE(i));
    expect(decoded).toBe('Remember to check this');
  });

  it('closed by default (Open: false), so it renders as a collapsed icon', async () => {
    const page = withAnnotations(blankPageRef({ width: 100, height: 100 }), [
      { id: newAnnotationId(), type: 'note', at: { x: 0.5, y: 0.5 }, text: 'x', color: '#000', opacity: 1 },
    ]);
    const out = await buildPdf({}, [page]);
    const [annot] = await getAnnots(out);
    expect(annot.get(PDFName.of('Open'))?.toString()).toBe('false');
  });
});

describe('flattened vs. real-annotation coexistence', () => {
  it('a page can have both flattened markup and a real link annotation', async () => {
    const page = withAnnotations(blankPageRef({ width: 200, height: 200 }), [
      { id: newAnnotationId(), type: 'whiteout', rect: { left: 0, top: 0, width: 0.2, height: 0.2 }, color: '#ffffff', opacity: 1 },
      { id: newAnnotationId(), type: 'link', rect: { left: 0.5, top: 0.5, width: 0.2, height: 0.2 }, opacity: 1, url: 'https://a.test' },
    ]);
    const out = await buildPdf({}, [page]);
    expect(await getAnnots(out)).toHaveLength(1); // only the link is a real annotation object
    const [readback] = await readBack(out);
    expect(readback.media).toMatchObject({ width: 200, height: 200 });
  });
});
