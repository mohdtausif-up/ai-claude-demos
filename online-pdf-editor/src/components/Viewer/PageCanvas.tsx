import { useEffect, useRef } from 'react';
import { displaySize } from '../../lib/geometry';
import type { PageRef, SourceDoc } from '../../lib/pageModel';
import { renderPageRef } from '../../lib/pdfRender';

interface Props {
  page: PageRef;
  sources: Record<string, SourceDoc>;
  cssScale: number;
  ignoreCrop?: boolean;
  onSize?: (widthPx: number, heightPx: number) => void;
}

/** Renders one page at full viewer resolution (as opposed to the thumbnail's low-res copy). */
export default function PageCanvas({ page, sources, cssScale, ignoreCrop, onSize }: Props) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const size = displaySize(page, { ignoreCrop });

  // The CSS layout size is derived here (not read back off the canvas), so the
  // wrapper div and crop overlay can lay out before the render finishes.
  useEffect(() => onSize?.(size.width * cssScale, size.height * cssScale), [size.width, size.height, cssScale, onSize]);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const handle = renderPageRef(page, sources, canvas, { cssScale, ignoreCrop });
    handle.promise.catch((err) => console.error('Page render failed:', err));
    return () => handle.cancel();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page.id, page.source, page.rotation, page.crop, page.resize, page.base, sources[page.source.kind === 'pdf' ? page.source.docId : ''], cssScale, ignoreCrop]);

  return <canvas ref={canvasRef} data-testid="page-canvas" className="block bg-white shadow-md" />;
}
