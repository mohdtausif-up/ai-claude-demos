import { useEffect, useRef } from 'react';
import { displaySize } from '../../lib/geometry';
import type { PageRef, SourceDoc } from '../../lib/pageModel';
import { renderPageRef } from '../../lib/pdfRender';

interface Props {
  page: PageRef;
  sources: Record<string, SourceDoc>;
  /** CSS pixels per PDF point, at 1x — the component scales the canvas element to fit `widthPx`. */
  widthPx: number;
}

/** A single page thumbnail, rendered via pdf.js at a resolution matched to its display size. */
export default function PageThumbnail({ page, sources, widthPx }: Props) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const size = displaySize(page);
  const heightPx = widthPx * (size.height / size.width);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const handle = renderPageRef(page, sources, canvas, { cssScale: widthPx / size.width });
    handle.promise.catch((err) => console.error('Thumbnail render failed:', err));
    return () => handle.cancel();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page.id, page.source, page.rotation, page.crop, page.resize, page.base, sources[page.source.kind === 'pdf' ? page.source.docId : ''], widthPx]);

  return (
    <canvas
      ref={canvasRef}
      style={{ width: widthPx, height: heightPx }}
      className="rounded border border-slate-300 bg-white shadow-sm"
      aria-hidden="true"
    />
  );
}
