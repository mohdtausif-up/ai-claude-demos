import { useEffect, useRef } from 'react';
import { canvasBoxToContentRect } from '../../lib/annotateGeometry';
import { newAnnotationId, type MarkupKind } from '../../lib/annotations';
import { totalRotation } from '../../lib/geometry';
import type { FracRect, PageRef, SourceDoc } from '../../lib/pageModel';
import { openPdfJsDoc } from '../../lib/pdfRender';
import { pdfjs } from '../../lib/pdfjsSetup';
import { useAnnotateStore } from '../../store/annotateStore';
import { addAnnotation } from '../../tools/edit/commands';
import { runCommand } from '../../tools/organize/actions';
import './textLayer.css';

interface Props {
  page: PageRef;
  sources: Record<string, SourceDoc>;
  containerSize: { width: number; height: number };
  /** Only highlight/underline/strikethrough are handled here — everything else uses AnnotateOverlay. */
  active: boolean;
}

const KIND_LABEL: Record<MarkupKind, string> = { highlight: 'Highlight', underline: 'Underline', strikethrough: 'Strikethrough' };

/**
 * Renders pdf.js's real text layer (invisible, selectable spans aligned to
 * the rendered glyphs) so the user can drag-select actual text, then turns
 * that browser selection into a highlight/underline/strikethrough annotation
 * — one rect per visual line-fragment of the selection.
 *
 * Only meaningful on the page's full, uncropped box (a crop only changes
 * what's *visible*, and pdf.js has no notion of our custom crop), so Viewer.tsx
 * renders the page with `ignoreCrop` while one of these tools is active, and
 * this component always measures against that same full-page viewport.
 */
export default function TextLayerHighlighter({ page, sources, containerSize, active }: Props) {
  const containerRef = useRef<HTMLDivElement>(null);
  const rotation = totalRotation(page);

  useEffect(() => {
    const container = containerRef.current;
    if (!container || !active || page.source.kind === 'blank') return;
    const src = sources[page.source.docId];
    if (!src) return;

    let cancelled = false;
    let layer: InstanceType<typeof pdfjs.TextLayer> | null = null;

    (async () => {
      const doc = await openPdfJsDoc(src);
      if (cancelled) return;
      const pdfPage = await doc.getPage((page.source as { pageIndex: number }).pageIndex + 1);
      if (cancelled) return;
      const unit = pdfPage.getViewport({ scale: 1, rotation });
      const scale = containerSize.width / unit.width;
      const viewport = pdfPage.getViewport({ scale, rotation });
      const textContent = await pdfPage.getTextContent();
      if (cancelled) return;

      container.innerHTML = '';
      container.style.setProperty('--total-scale-factor', String(scale));
      container.style.width = `${viewport.width}px`;
      container.style.height = `${viewport.height}px`;

      layer = new pdfjs.TextLayer({ textContentSource: textContent, container, viewport });
      await layer.render();
    })().catch((err) => console.error('Text layer failed to render:', err));

    return () => {
      cancelled = true;
      layer?.cancel();
    };
  }, [page.id, page.source, sources, containerSize.width, containerSize.height, rotation, active]);

  useEffect(() => {
    if (!active) return;
    const onMouseUp = () => {
      const container = containerRef.current;
      const selection = window.getSelection();
      if (!container || !selection || selection.isCollapsed || selection.rangeCount === 0) return;
      const range = selection.getRangeAt(0);
      if (!container.contains(range.commonAncestorContainer)) return;

      const containerBox = container.getBoundingClientRect();
      const clientRects = [...range.getClientRects()].filter((r) => r.width > 0.5 && r.height > 0.5);
      if (clientRects.length === 0) return;

      const rects: FracRect[] = clientRects.map((r) =>
        canvasBoxToContentRect(
          { left: r.left - containerBox.left, top: r.top - containerBox.top, width: r.width, height: r.height },
          rotation,
          containerSize,
        ),
      );
      const kind = useAnnotateStore.getState().tool as MarkupKind;
      const color = useAnnotateStore.getState().style.markupColor;
      runCommand(addAnnotation(page.id, { id: newAnnotationId(), type: 'markup', kind, rects, color, opacity: kind === 'highlight' ? 0.4 : 1 }, KIND_LABEL[kind]));
      selection.removeAllRanges();
    };
    document.addEventListener('mouseup', onMouseUp);
    return () => document.removeEventListener('mouseup', onMouseUp);
  }, [active, page.id, rotation, containerSize]);

  if (!active) return null;
  return <div ref={containerRef} className="textLayer absolute inset-0" style={{ pointerEvents: 'auto' }} />;
}
