import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import AnnotateOverlay from '../Annotate/AnnotateOverlay';
import TextLayerHighlighter from '../Annotate/TextLayerHighlighter';
import FormFillOverlay from '../Forms/FormFillOverlay';
import { FULL_RECT, rotateFracRect, totalRotation, unrotateFracRect } from '../../lib/geometry';
import { useAnnotateStore } from '../../store/annotateStore';
import { useDocumentStore } from '../../store/documentStore';
import { useUiStore, type Zoom } from '../../store/uiStore';
import { setCrops } from '../../tools/organize/commands';
import { runCommand } from '../../tools/organize/actions';
import CropOverlay from './CropOverlay';
import PageCanvas from './PageCanvas';

const MARKUP_TEXT_TOOLS = new Set(['highlight', 'underline', 'strikethrough']);

const ZOOM_STEPS = [0.5, 0.75, 1, 1.25, 1.5, 2, 3];
/** CSS pixels per PDF point at "100%" zoom: 96 CSS px/inch over 72 PDF points/inch. */
const CSS_PX_PER_POINT_AT_100 = 96 / 72;

function useContainerSize<T extends HTMLElement>() {
  const ref = useRef<T>(null);
  const [size, setSize] = useState({ width: 0, height: 0 });
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const ro = new ResizeObserver(([entry]) => setSize({ width: entry.contentRect.width, height: entry.contentRect.height }));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  return [ref, size] as const;
}

export default function Viewer() {
  const doc = useDocumentStore((s) => s.doc);
  const currentPageId = useUiStore((s) => s.currentPageId);
  const scrollRequest = useUiStore((s) => s.scrollRequest);
  const zoom = useUiStore((s) => s.zoom);
  const setZoom = useUiStore((s) => s.setZoom);
  const setEffectiveScale = useUiStore((s) => s.setEffectiveScale);
  const activeTool = useUiStore((s) => s.activeTool);
  const cropDraft = useUiStore((s) => s.cropDraft);
  const setCropDraft = useUiStore((s) => s.setCropDraft);
  const goToPage = useUiStore((s) => s.goToPage);

  const [containerRef, containerSize] = useContainerSize<HTMLDivElement>();
  const [pageSizePx, setPageSizePx] = useState({ width: 0, height: 0 });
  const cropping = activeTool === 'crop';
  const annotating = activeTool === 'annotate';
  const fillingForm = activeTool === 'fill-form';
  const annotateTool = useAnnotateStore((s) => s.tool);
  // The text-selection tools need the page's full, uncropped box so pdf.js's
  // text layer (which has no notion of our custom crop) stays aligned with it.
  const isMarkupTextTool = annotating && MARKUP_TEXT_TOOLS.has(annotateTool);
  // Form field widget rects are likewise stored in the page's original,
  // uncropped space (see formFields.ts) with no notion of our custom crop.
  const showFullPage = cropping || isMarkupTextTool || fillingForm;

  // Stable identity + change-guarded, so PageCanvas's effect (which depends on
  // this callback) doesn't re-run — and re-render this component — every frame.
  const handlePageSize = useCallback(
    (width: number, height: number) =>
      setPageSizePx((prev) => (prev.width === width && prev.height === height ? prev : { width, height })),
    [],
  );

  const page = doc?.pages.find((p) => p.id === currentPageId) ?? doc?.pages[0] ?? null;
  const index = page ? doc!.pages.indexOf(page) : -1;

  useEffect(() => {
    if (page && cropping) {
      const rotation = totalRotation(page);
      setCropDraft(page.crop ? rotateFracRect(page.crop, rotation) : FULL_RECT);
    } else {
      setCropDraft(null);
    }
  }, [page?.id, cropping, setCropDraft]);

  const cssScale = useMemo(() => {
    if (!page || containerSize.width === 0) return 1;
    const padding = 32;
    if (zoom === 'fit-width') return Math.max(0.1, (containerSize.width - padding * 2) / basePageWidth(page));
    if (zoom === 'fit-page')
      return Math.max(
        0.1,
        Math.min(
          (containerSize.width - padding * 2) / basePageWidth(page),
          (containerSize.height - padding * 2) / basePageHeight(page),
        ),
      );
    return zoom * CSS_PX_PER_POINT_AT_100;
  }, [zoom, containerSize, page]);

  useEffect(() => setEffectiveScale(cssScale), [cssScale, setEffectiveScale]);

  const scrollRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    scrollRef.current?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scrollRequest]);

  if (!doc || !page) return null;

  const applyCrop = () => {
    if (!cropDraft) return;
    const unrotated = unrotateFracRect(cropDraft, totalRotation(page));
    runCommand(setCrops({ [page.id]: unrotated }));
    useUiStore.getState().setTool('organize');
  };

  const nav = (delta: number) => {
    const next = doc.pages[index + delta];
    if (next) goToPage(next.id);
  };

  return (
    <div className="flex h-full min-h-0 flex-1 flex-col">
      <div ref={containerRef} className="flex min-h-0 flex-1 items-center justify-center overflow-auto bg-slate-200 p-8">
        <div ref={scrollRef} className="relative" style={{ width: pageSizePx.width, height: pageSizePx.height }}>
          <PageCanvas page={page} sources={doc.sources} cssScale={cssScale} ignoreCrop={showFullPage} onSize={handlePageSize} />
          {cropping && cropDraft && <CropOverlay rect={cropDraft} onChange={setCropDraft} containerSize={pageSizePx} />}
          {/* AnnotateOverlay stays mounted (so existing annotations remain visible) even while a
              markup-text tool is active — it just becomes non-interactive via its own pointer-events toggle. */}
          {annotating && <AnnotateOverlay page={page} containerSize={pageSizePx} />}
          {annotating && (
            <TextLayerHighlighter page={page} sources={doc.sources} containerSize={pageSizePx} active={isMarkupTextTool} />
          )}
          {fillingForm && <FormFillOverlay page={page} containerSize={pageSizePx} />}
        </div>
      </div>

      <div className="flex items-center justify-between border-t border-slate-200 bg-white px-4 py-2">
        <div className="flex items-center gap-2">
          <button type="button" disabled={index <= 0} onClick={() => nav(-1)} className="rounded px-2 py-1 text-sm hover:bg-slate-100 disabled:opacity-40">
            ◀
          </button>
          <span className="text-sm text-slate-600">
            Page {index + 1} of {doc.pages.length}
          </span>
          <button type="button" disabled={index >= doc.pages.length - 1} onClick={() => nav(1)} className="rounded px-2 py-1 text-sm hover:bg-slate-100 disabled:opacity-40">
            ▶
          </button>
        </div>

        {cropping ? (
          <div className="flex items-center gap-2">
            <button type="button" onClick={() => useUiStore.getState().setTool('organize')} className="rounded-md px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100">
              Cancel
            </button>
            <button type="button" onClick={applyCrop} className="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700">
              Apply crop
            </button>
          </div>
        ) : (
          <ZoomControl zoom={zoom} setZoom={setZoom} />
        )}
      </div>
    </div>
  );
}

function basePageWidth(page: { base: { width: number; height: number }; rotation: number }): number {
  const swap = ((page.rotation / 90) % 2 + 2) % 2 === 1;
  return swap ? page.base.height : page.base.width;
}
function basePageHeight(page: { base: { width: number; height: number }; rotation: number }): number {
  const swap = ((page.rotation / 90) % 2 + 2) % 2 === 1;
  return swap ? page.base.width : page.base.height;
}

function ZoomControl({ zoom, setZoom }: { zoom: Zoom; setZoom: (z: Zoom) => void }) {
  return (
    <div className="flex items-center gap-1 text-sm">
      <button type="button" onClick={() => setZoom('fit-page')} className={`rounded px-2 py-1 hover:bg-slate-100 ${zoom === 'fit-page' ? 'font-semibold text-blue-600' : 'text-slate-600'}`}>
        Fit page
      </button>
      <button type="button" onClick={() => setZoom('fit-width')} className={`rounded px-2 py-1 hover:bg-slate-100 ${zoom === 'fit-width' ? 'font-semibold text-blue-600' : 'text-slate-600'}`}>
        Fit width
      </button>
      <select
        value={typeof zoom === 'number' ? zoom : ''}
        onChange={(e) => setZoom(Number(e.target.value))}
        className="rounded border border-slate-200 px-1.5 py-1 text-slate-700"
        aria-label="Zoom level"
      >
        <option value="" disabled>
          Zoom
        </option>
        {ZOOM_STEPS.map((z) => (
          <option key={z} value={z}>
            {Math.round(z * 100)}%
          </option>
        ))}
      </select>
    </div>
  );
}
