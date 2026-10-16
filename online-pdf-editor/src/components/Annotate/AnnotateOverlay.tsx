import * as fabric from 'fabric';
import { useEffect, useRef, useState } from 'react';
import {
  canvasBoxToContentRect,
  canvasPointToContent,
  contentPointToCanvas,
  contentRectToCanvasBox,
  type PixelBox,
} from '../../lib/annotateGeometry';
import { newAnnotationId, type AnnotationObject, type FontStyle, type Point } from '../../lib/annotations';
import { totalRotation } from '../../lib/geometry';
import type { PageRef } from '../../lib/pageModel';
import { runCommand } from '../../tools/organize/actions';
import { addAnnotation, updateAnnotation } from '../../tools/edit/commands';
import { resolveFillStroke, TOOL_ANNOTATION_TYPE, useAnnotateStore, type AnnotateTool } from '../../store/annotateStore';
import AnnotatePromptDialog from './AnnotatePromptDialog';
import SignaturePickerDialog from './SignaturePickerDialog';

interface Props {
  page: PageRef;
  containerSize: { width: number; height: number };
}

const FONT_FAMILY_CSS: Record<string, string> = {
  Helvetica: 'Helvetica, Arial, sans-serif',
  Times: '"Times New Roman", Times, serif',
  Courier: '"Courier New", Courier, monospace',
};

const MIN_DRAG_PX = 3;
const NOTE_ICON_PX = 22;

/** Minimum size a click-without-drag placement gets, as a fraction of the canvas. */
const DEFAULT_SHAPE_FRAC = 0.2;

function fontCss(font: FontStyle): { fontFamily: string; fontWeight: 'bold' | 'normal'; fontStyle: 'italic' | 'normal' } {
  return {
    fontFamily: FONT_FAMILY_CSS[font.family],
    fontWeight: font.bold ? 'bold' : 'normal',
    fontStyle: font.italic ? 'italic' : 'normal',
  };
}

/** Per-object bookkeeping the pure annotation model doesn't need but Fabric interaction does. */
interface ObjMeta {
  id: string;
  /** For line/arrow: the object's own left/top at creation, and the content-space endpoints then — a
   * pure-translation delta from these reconstructs the new endpoints (see commitObjectChange). */
  line?: { createdLeft: number; createdTop: number; start: Point; end: Point };
}

/**
 * The interactive annotation layer: a Fabric.js canvas over the current page,
 * translating between Fabric objects (the editing surface) and the plain
 * AnnotationObject model (the source of truth, persisted via undo/redo
 * commands). Fabric itself is never part of the persisted state or the
 * flatten-to-PDF path — see lib/annotations.ts and lib/flattenAnnotations.ts.
 */
export default function AnnotateOverlay({ page, containerSize }: Props) {
  const canvasElRef = useRef<HTMLCanvasElement>(null);
  const fabricRef = useRef<fabric.Canvas | null>(null);
  const idToObj = useRef(new Map<string, fabric.Object>());
  const objMeta = useRef(new WeakMap<fabric.Object, ObjMeta>());
  const dragRef = useRef<{ tool: AnnotateTool; start: Point; obj: fabric.Object } | null>(null);
  const [prompt, setPrompt] = useState<{ kind: 'link' | 'note'; rect: PixelBox } | null>(null);
  const [signaturePicker, setSignaturePicker] = useState(false);
  const rebuildGeneration = useRef(0);

  const tool = useAnnotateStore((s) => s.tool);
  const style = useAnnotateStore((s) => s.style);
  const setTool = useAnnotateStore((s) => s.setTool);
  const setSelectedId = useAnnotateStore((s) => s.setSelectedId);
  const rotation = totalRotation(page);

  // Keep latest values reachable from event handlers registered once on mount.
  const latest = useRef({ tool, style, rotation, containerSize, page });
  latest.current = { tool, style, rotation, containerSize, page };

  const commit = (annotation: AnnotationObject, label: string) => runCommand(addAnnotation(latest.current.page.id, annotation, label));

  function registerObject(obj: fabric.Object, id: string, meta: Omit<ObjMeta, 'id'> = {}) {
    idToObj.current.set(id, obj);
    // `id` last: buildFabricObject may have already stashed its own (identical)
    // meta for line/arrow objects, but the id passed in here is authoritative.
    objMeta.current.set(obj, { ...meta, id });
  }

  function commitObjectChange(obj: fabric.Object) {
    const meta = objMeta.current.get(obj);
    if (!meta) return;
    const { rotation: rot, containerSize: size, page: pg } = latest.current;
    if (meta.line) {
      const dLeft = obj.left! - meta.line.createdLeft;
      const dTop = obj.top! - meta.line.createdTop;
      const startPx = contentPointToCanvas(meta.line.start, rot, size);
      const endPx = contentPointToCanvas(meta.line.end, rot, size);
      const start = canvasPointToContent({ x: startPx.x + dLeft, y: startPx.y + dTop }, rot, size);
      const end = canvasPointToContent({ x: endPx.x + dLeft, y: endPx.y + dTop }, rot, size);
      objMeta.current.set(obj, { ...meta, line: { createdLeft: obj.left!, createdTop: obj.top!, start, end } });
      runCommand(updateAnnotation(pg.id, meta.id, { start, end } as Partial<AnnotationObject>, 'Move line'));
      return;
    }
    const box: PixelBox = { left: obj.left!, top: obj.top!, width: obj.getScaledWidth(), height: obj.getScaledHeight() };
    const rect = canvasBoxToContentRect(box, rot, size);
    runCommand(updateAnnotation(pg.id, meta.id, { rect } as Partial<AnnotationObject>, 'Move/resize'));
  }

  function buildFabricObject(a: AnnotationObject): fabric.Object | null {
    const { rotation: rot, containerSize: size } = latest.current;
    const common = { originX: 'left' as const, originY: 'top' as const, opacity: a.opacity };

    switch (a.type) {
      case 'rectangle':
      case 'ellipse': {
        const box = contentRectToCanvasBox(a.rect, rot, size);
        const shared = {
          ...common,
          left: box.left,
          top: box.top,
          fill: a.fillColor ?? 'transparent',
          stroke: a.strokeColor ?? undefined,
          strokeWidth: a.strokeColor ? a.strokeWidth : 0,
        };
        const obj =
          a.type === 'rectangle'
            ? new fabric.Rect({ ...shared, width: box.width, height: box.height })
            : new fabric.Ellipse({ ...shared, rx: box.width / 2, ry: box.height / 2 });
        obj.setControlsVisibility({ mtr: false });
        obj.lockRotation = true;
        return obj;
      }
      case 'whiteout':
      case 'redact': {
        const box = contentRectToCanvasBox(a.rect, rot, size);
        const obj = new fabric.Rect({
          ...common,
          left: box.left,
          top: box.top,
          width: box.width,
          height: box.height,
          fill: a.color,
          stroke: a.type === 'redact' ? '#dc2626' : '#94a3b8',
          strokeWidth: 1,
          strokeDashArray: a.type === 'redact' ? [6, 4] : undefined,
        });
        obj.setControlsVisibility({ mtr: false });
        obj.lockRotation = true;
        return obj;
      }
      case 'link': {
        const box = contentRectToCanvasBox(a.rect, rot, size);
        const obj = new fabric.Rect({
          ...common,
          left: box.left,
          top: box.top,
          width: box.width,
          height: box.height,
          fill: 'rgba(37, 99, 235, 0.15)',
          stroke: '#2563eb',
          strokeWidth: 1.5,
          strokeDashArray: [4, 3],
        });
        obj.setControlsVisibility({ mtr: false });
        obj.lockRotation = true;
        return obj;
      }
      case 'text': {
        const box = contentRectToCanvasBox(a.rect, rot, size);
        const obj = new fabric.Textbox(a.text, {
          ...common,
          left: box.left,
          top: box.top,
          width: box.width,
          fontSize: a.fontSize,
          fill: a.color,
          textAlign: a.align,
          ...fontCss(a.font),
        });
        obj.setControlsVisibility({ mtr: false });
        obj.lockRotation = true;
        return obj;
      }
      case 'line':
      case 'arrow': {
        const start = contentPointToCanvas(a.start, rot, size);
        const end = contentPointToCanvas(a.end, rot, size);
        const obj = new fabric.Line([start.x, start.y, end.x, end.y], {
          ...common,
          stroke: a.strokeColor,
          strokeWidth: a.strokeWidth,
          hasControls: false,
        });
        objMeta.current.set(obj, { id: a.id, line: { createdLeft: obj.left!, createdTop: obj.top!, start: a.start, end: a.end } });
        return obj;
      }
      case 'draw': {
        const pts = a.points.map((p) => contentPointToCanvas(p, rot, size));
        const d = pts.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x} ${p.y}`).join(' ');
        return new fabric.Path(d, { ...common, stroke: a.strokeColor, strokeWidth: a.strokeWidth, fill: undefined, hasControls: false });
      }
      case 'note': {
        const at = contentPointToCanvas(a.at, rot, size);
        return new fabric.Rect({
          ...common,
          left: at.x - NOTE_ICON_PX / 2,
          top: at.y - NOTE_ICON_PX / 2,
          width: NOTE_ICON_PX,
          height: NOTE_ICON_PX,
          fill: a.color,
          stroke: '#78716c',
          strokeWidth: 1,
          rx: 3,
          ry: 3,
          hasControls: false,
        });
      }
      case 'markup': {
        // One rect per line fragment, grouped so they select/delete as a unit.
        const boxes = a.rects.map((r) => contentRectToCanvasBox(r, rot, size));
        const rects = boxes.map(
          (box) =>
            new fabric.Rect({
              left: box.left,
              top: box.top,
              width: box.width,
              height: box.height,
              fill: a.kind === 'highlight' ? a.color : 'transparent',
              stroke: a.kind === 'highlight' ? undefined : a.color,
              strokeWidth: a.kind === 'highlight' ? 0 : Math.max(1, box.height * 0.08),
            }),
        );
        const group = new fabric.Group(rects, { ...common, hasControls: false, lockMovementX: true, lockMovementY: true });
        return group;
      }
      default:
        return null;
    }
  }

  function rebuildObjects(canvas: fabric.Canvas) {
    canvas.clear();
    idToObj.current.clear();
    const generation = ++rebuildGeneration.current;
    const selectedId = useAnnotateStore.getState().selectedId;
    for (const a of latest.current.page.annotations) {
      // Fabric's image loading is async, unlike every other annotation type
      // here — handled as a special case so it doesn't force the rest of
      // this synchronous rebuild into a Promise chain. `generation` guards
      // against a slow load resolving after a newer rebuild already cleared
      // this canvas (e.g. rapid undo/redo).
      if (a.type === 'signature') {
        const { rotation: rot, containerSize: size } = latest.current;
        const box = contentRectToCanvasBox(a.rect, rot, size);
        void fabric.FabricImage.fromURL(a.imageDataUrl, {}, { left: box.left, top: box.top, originX: 'left', originY: 'top', opacity: a.opacity }).then(
          (img) => {
            if (rebuildGeneration.current !== generation) return;
            img.scaleX = box.width / (img.width || box.width);
            img.scaleY = box.height / (img.height || box.height);
            img.setControlsVisibility({ mtr: false });
            img.lockRotation = true;
            img.selectable = latest.current.tool === 'select';
            registerObject(img, a.id);
            canvas.add(img);
            if (a.id === selectedId) canvas.setActiveObject(img);
            canvas.requestRenderAll();
          },
        );
        continue;
      }
      const obj = buildFabricObject(a);
      if (!obj) continue;
      registerObject(obj, a.id, objMeta.current.get(obj));
      canvas.add(obj);
      if (a.id === selectedId) canvas.setActiveObject(obj);
    }
    canvas.requestRenderAll();
  }

  // ---- create the Fabric canvas once per mounted page ----
  useEffect(() => {
    const el = canvasElRef.current;
    if (!el) return;
    const canvas = new fabric.Canvas(el, { selection: true, preserveObjectStacking: true, stopContextMenu: true });
    // Fabric moves the original <canvas> into its own wrapper div and drops
    // any classes/positioning we'd put on the element itself (our "absolute
    // inset-0" Tailwind class only ever applied pre-Fabric) — the wrapper is
    // what actually needs to overlay the page canvas underneath it.
    Object.assign(canvas.wrapperEl.style, { position: 'absolute', inset: '0' });
    fabricRef.current = canvas;

    const syncSelection = (selected?: fabric.Object[]) => {
      const obj = selected?.[0];
      setSelectedId(obj ? (objMeta.current.get(obj)?.id ?? null) : null);
    };
    canvas.on('selection:created', (e) => syncSelection(e.selected));
    canvas.on('selection:updated', (e) => syncSelection(e.selected));
    canvas.on('selection:cleared', () => setSelectedId(null));
    canvas.on('object:modified', (e) => e.target && commitObjectChange(e.target));

    canvas.on('path:created', (e) => {
      const path = (e as unknown as { path: fabric.Path }).path;
      canvas.remove(path);
      const segments = (path.path ?? []) as unknown as [string, ...number[]][];
      const pxPoints: Point[] = [];
      for (const seg of segments) {
        const [cmd, ...nums] = seg;
        if (cmd === 'M' || cmd === 'L') pxPoints.push({ x: nums[0], y: nums[1] });
        else if (cmd === 'Q') pxPoints.push({ x: nums[2], y: nums[3] });
        else if (cmd === 'C') pxPoints.push({ x: nums[4], y: nums[5] });
      }
      if (pxPoints.length < 2) return;
      const { rotation: rot, containerSize: size, style: st } = latest.current;
      const points = pxPoints.map((p) => canvasPointToContent(p, rot, size));
      commit({ id: newAnnotationId(), type: 'draw', points, strokeColor: st.strokeColor, strokeWidth: st.strokeWidth, opacity: 1 }, 'Draw');
    });

    const onMouseDown = (opt: fabric.TPointerEventInfo) => {
      const { tool: t, style: st, rotation: rot, containerSize: size } = latest.current;
      if (t === 'select' || t === 'draw' || t === 'highlight' || t === 'underline' || t === 'strikethrough') return;
      const p = opt.scenePoint;

      // With skipTargetFind on (any non-select tool), Fabric can't process a
      // click as "deselect" the normal way, so placing text a second time
      // without switching tools would otherwise leave the first box's edits
      // uncommitted. Commit it explicitly first.
      const active = canvas.getActiveObject();
      if (active instanceof fabric.Textbox && active.isEditing) active.exitEditing();

      if (t === 'text') {
        const box: PixelBox = { left: p.x, top: p.y, width: size.width * DEFAULT_SHAPE_FRAC, height: st.fontSize * 1.4 };
        const obj = new fabric.Textbox('Text', {
          left: box.left,
          top: box.top,
          width: box.width,
          fontSize: st.fontSize,
          fill: st.textColor,
          textAlign: st.align,
          originX: 'left',
          originY: 'top',
          ...fontCss(st.font),
        });
        obj.setControlsVisibility({ mtr: false });
        obj.lockRotation = true;
        canvas.add(obj);
        canvas.setActiveObject(obj);
        obj.enterEditing();
        obj.selectAll();
        const finish = () => {
          const text = obj.text?.trim();
          if (!text) {
            canvas.remove(obj);
            setTool('select');
            return;
          }
          const finalBox: PixelBox = { left: obj.left!, top: obj.top!, width: obj.getScaledWidth(), height: obj.getScaledHeight() };
          const rect = canvasBoxToContentRect(finalBox, rot, size);
          const id = newAnnotationId();
          canvas.remove(obj);
          commit({ id, type: 'text', rect, text, font: st.font, fontSize: st.fontSize, color: st.textColor, align: st.align, opacity: 1 }, 'Add text');
          setTool('select');
        };
        obj.once('editing:exited', finish);
        return;
      }

      if (t === 'note') {
        setPrompt({ kind: 'note', rect: { left: p.x - NOTE_ICON_PX / 2, top: p.y - NOTE_ICON_PX / 2, width: NOTE_ICON_PX, height: NOTE_ICON_PX } });
        return;
      }

      if (t === 'line' || t === 'arrow') {
        const obj = new fabric.Line([p.x, p.y, p.x, p.y], { stroke: st.strokeColor, strokeWidth: st.strokeWidth, selectable: false });
        canvas.add(obj);
        dragRef.current = { tool: t, start: p, obj };
        return;
      }

      // rectangle / ellipse / whiteout / redact / link: drag a box.
      const { fillColor, strokeColor } = resolveFillStroke(st);
      const previewFill =
        t === 'whiteout' ? '#ffffff' : t === 'redact' ? st.redactColor : t === 'link' ? 'rgba(37,99,235,0.15)' : (fillColor ?? 'transparent');
      const previewStroke = t === 'redact' ? '#dc2626' : t === 'link' ? '#2563eb' : (strokeColor ?? undefined);
      const obj = new fabric.Rect({
        left: p.x,
        top: p.y,
        width: 0,
        height: 0,
        fill: previewFill,
        stroke: previewStroke,
        strokeWidth: t === 'rectangle' || t === 'ellipse' ? (strokeColor ? st.strokeWidth : 0) : 1.5,
        strokeDashArray: t === 'redact' || t === 'link' ? [5, 4] : undefined,
        selectable: false,
        originX: 'left',
        originY: 'top',
      });
      canvas.add(obj);
      dragRef.current = { tool: t, start: p, obj };
    };

    const onMouseMove = (opt: fabric.TPointerEventInfo) => {
      const drag = dragRef.current;
      if (!drag) return;
      const p = opt.scenePoint;
      if (drag.obj instanceof fabric.Line) {
        drag.obj.set({ x2: p.x, y2: p.y });
      } else {
        drag.obj.set({
          left: Math.min(drag.start.x, p.x),
          top: Math.min(drag.start.y, p.y),
          width: Math.abs(p.x - drag.start.x),
          height: Math.abs(p.y - drag.start.y),
        });
      }
      canvas.requestRenderAll();
    };

    const onMouseUp = (opt: fabric.TPointerEventInfo) => {
      const drag = dragRef.current;
      dragRef.current = null;
      if (!drag) return;
      const { rotation: rot, containerSize: size, style: st } = latest.current;
      const end = opt.scenePoint;
      const dragged = Math.hypot(end.x - drag.start.x, end.y - drag.start.y) > MIN_DRAG_PX;

      if (drag.obj instanceof fabric.Line) {
        canvas.remove(drag.obj);
        const endPoint = dragged ? end : { x: drag.start.x + size.width * DEFAULT_SHAPE_FRAC, y: drag.start.y };
        const start = canvasPointToContent(drag.start, rot, size);
        const finish = canvasPointToContent(endPoint, rot, size);
        commit(
          { id: newAnnotationId(), type: drag.tool as 'line' | 'arrow', start, end: finish, strokeColor: st.strokeColor, strokeWidth: st.strokeWidth, opacity: 1 },
          drag.tool === 'arrow' ? 'Add arrow' : 'Add line',
        );
        return;
      }

      const box: PixelBox = dragged
        ? { left: drag.obj.left!, top: drag.obj.top!, width: drag.obj.width!, height: drag.obj.height! }
        : { left: drag.start.x, top: drag.start.y, width: size.width * DEFAULT_SHAPE_FRAC, height: size.width * DEFAULT_SHAPE_FRAC * 0.6 };
      canvas.remove(drag.obj);

      if (drag.tool === 'link') {
        setPrompt({ kind: 'link', rect: box });
        return;
      }

      const rect = canvasBoxToContentRect(box, rot, size);
      if (drag.tool === 'rectangle' || drag.tool === 'ellipse') {
        const { fillColor, strokeColor } = resolveFillStroke(st);
        commit({ id: newAnnotationId(), type: drag.tool, rect, fillColor, strokeColor, strokeWidth: st.strokeWidth, opacity: st.opacity }, `Add ${drag.tool}`);
      } else if (drag.tool === 'whiteout') {
        commit({ id: newAnnotationId(), type: 'whiteout', rect, color: '#ffffff', opacity: 1 }, 'Add whiteout');
      } else if (drag.tool === 'redact') {
        commit({ id: newAnnotationId(), type: 'redact', rect, mode: st.redactMode, color: st.redactColor, opacity: 1 }, 'Add redaction');
      }
      setTool('select');
    };

    canvas.on('mouse:down', onMouseDown);
    canvas.on('mouse:move', onMouseMove);
    canvas.on('mouse:up', onMouseUp);

    rebuildObjects(canvas);

    return () => {
      canvas.dispose();
      fabricRef.current = null;
      idToObj.current.clear();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page.id]);

  // Rebuild displayed objects whenever the page's annotations, canvas size or
  // rotation change (undo/redo, resize, page rotate) — cheap for the sizes
  // this app deals with, and much simpler than incrementally patching Fabric
  // objects to match an externally-changed model.
  useEffect(() => {
    const canvas = fabricRef.current;
    if (!canvas) return;
    // Fabric only reads the <canvas> element's width/height attributes once,
    // at construction — it owns sizing itself from then on, so a plain React
    // prop update here would silently be ignored without this.
    if (canvas.width !== containerSize.width || canvas.height !== containerSize.height) {
      canvas.setDimensions({ width: containerSize.width, height: containerSize.height });
    }
    rebuildObjects(canvas);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page.annotations, containerSize.width, containerSize.height, rotation]);

  // Picking "Signature" has no drag-to-place step (unlike link/note) — it
  // opens the picker immediately and places the result at a default spot,
  // same as a click-without-drag placement elsewhere in this file.
  useEffect(() => {
    if (tool === 'signature') setSignaturePicker(true);
  }, [tool]);

  // Tool-mode wiring: which interactions are active right now.
  useEffect(() => {
    const canvas = fabricRef.current;
    if (!canvas) return;
    // Switching tools (e.g. clicking "Select" after typing) must commit any
    // in-progress text edit — discardActiveObject alone doesn't reliably
    // trigger a Textbox's own editing:exited handling.
    const active = canvas.getActiveObject();
    if (active instanceof fabric.Textbox && active.isEditing) active.exitEditing();
    const isMarkupTextTool = tool === 'highlight' || tool === 'underline' || tool === 'strikethrough';
    canvas.isDrawingMode = tool === 'draw';
    canvas.selection = tool === 'select';
    canvas.skipTargetFind = tool !== 'select';
    if (canvas.isDrawingMode) {
      canvas.freeDrawingBrush = new fabric.PencilBrush(canvas);
      canvas.freeDrawingBrush.color = style.strokeColor;
      canvas.freeDrawingBrush.width = style.strokeWidth;
    }
    canvas.forEachObject((o) => (o.selectable = tool === 'select'));
    if (tool !== 'select') canvas.discardActiveObject();
    canvas.defaultCursor = tool === 'select' ? 'default' : isMarkupTextTool ? 'default' : 'crosshair';
    // The text layer (for highlight/underline/strikethrough) sits below this
    // canvas and needs pointer events when one of those tools is active.
    canvas.upperCanvasEl.style.pointerEvents = isMarkupTextTool ? 'none' : 'auto';
    canvas.requestRenderAll();
  }, [tool, style.strokeColor, style.strokeWidth]);

  // Deleting the selected annotation is handled centrally in Editor.tsx (it
  // takes priority over page-selection delete there) — running the command
  // updates page.annotations, which the effect above already reacts to by
  // rebuilding, so no direct Fabric manipulation is needed from here.

  return (
    <>
      <canvas ref={canvasElRef} data-testid="annotate-canvas" width={containerSize.width} height={containerSize.height} className="absolute inset-0" />
      {prompt && (
        <AnnotatePromptDialog
          kind={prompt.kind}
          onCancel={() => setPrompt(null)}
          onSubmit={(value) => {
            const rect = canvasBoxToContentRect(prompt.rect, latest.current.rotation, latest.current.containerSize);
            if (prompt.kind === 'link') {
              commit({ id: newAnnotationId(), type: 'link', rect, url: value, opacity: 1 }, 'Add link');
            } else {
              const at = canvasPointToContent(
                { x: prompt.rect.left + prompt.rect.width / 2, y: prompt.rect.top + prompt.rect.height / 2 },
                latest.current.rotation,
                latest.current.containerSize,
              );
              commit({ id: newAnnotationId(), type: 'note', at, text: value, color: latest.current.style.noteColor, opacity: 1 }, 'Add note');
            }
            setPrompt(null);
            setTool('select');
          }}
        />
      )}
      {signaturePicker && (
        <SignaturePickerDialog
          onCancel={() => {
            setSignaturePicker(false);
            setTool('select');
          }}
          onConfirm={(dataUrl) => {
            setSignaturePicker(false);
            const { rotation: rot, containerSize: size } = latest.current;
            const img = new Image();
            img.onload = () => {
              const naturalWidth = img.naturalWidth || 1;
              const naturalHeight = img.naturalHeight || 1;
              const width = size.width * 0.3;
              const height = width * (naturalHeight / naturalWidth);
              const box: PixelBox = {
                left: (size.width - width) / 2,
                top: size.height * 0.6 - height / 2,
                width,
                height,
              };
              const rect = canvasBoxToContentRect(box, rot, size);
              commit({ id: newAnnotationId(), type: 'signature', rect, imageDataUrl: dataUrl, opacity: 1 }, 'Add signature');
              setTool('select');
            };
            img.src = dataUrl;
          }}
        />
      )}
    </>
  );
}

export { TOOL_ANNOTATION_TYPE };
