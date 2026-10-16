import { useRef, useState, type PointerEvent as ReactPointerEvent } from 'react';
import { clampFracRect } from '../../lib/geometry';
import type { FracRect } from '../../lib/pageModel';

type Handle = 'move' | 'nw' | 'ne' | 'sw' | 'se' | 'n' | 's' | 'e' | 'w';

interface Props {
  rect: FracRect;
  onChange: (rect: FracRect) => void;
  /** Size of the page as displayed, in CSS pixels — for converting pointer deltas to fractions. */
  containerSize: { width: number; height: number };
}

const HANDLES: { id: Handle; className: string }[] = [
  { id: 'nw', className: 'left-0 top-0 -translate-x-1/2 -translate-y-1/2 cursor-nwse-resize' },
  { id: 'n', className: 'left-1/2 top-0 -translate-x-1/2 -translate-y-1/2 cursor-ns-resize' },
  { id: 'ne', className: 'right-0 top-0 translate-x-1/2 -translate-y-1/2 cursor-nesw-resize' },
  { id: 'e', className: 'right-0 top-1/2 translate-x-1/2 -translate-y-1/2 cursor-ew-resize' },
  { id: 'se', className: 'right-0 bottom-0 translate-x-1/2 translate-y-1/2 cursor-nwse-resize' },
  { id: 's', className: 'left-1/2 bottom-0 -translate-x-1/2 translate-y-1/2 cursor-ns-resize' },
  { id: 'sw', className: 'left-0 bottom-0 -translate-x-1/2 translate-y-1/2 cursor-nesw-resize' },
  { id: 'w', className: 'left-0 top-1/2 -translate-x-1/2 -translate-y-1/2 cursor-ew-resize' },
];

/** Draggable/resizable rectangle for the crop tool, in fractional page coordinates. */
export default function CropOverlay({ rect, onChange, containerSize }: Props) {
  const [drag, setDrag] = useState<{ handle: Handle; start: FracRect; startX: number; startY: number } | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  const begin = (handle: Handle) => (e: ReactPointerEvent) => {
    e.preventDefault();
    e.stopPropagation();
    (e.target as Element).setPointerCapture(e.pointerId);
    setDrag({ handle, start: rect, startX: e.clientX, startY: e.clientY });
  };

  const onMove = (e: ReactPointerEvent) => {
    if (!drag || containerSize.width === 0) return;
    const dx = (e.clientX - drag.startX) / containerSize.width;
    const dy = (e.clientY - drag.startY) / containerSize.height;
    let { left, top, width, height } = drag.start;

    if (drag.handle === 'move') {
      left += dx;
      top += dy;
    } else {
      if (drag.handle.includes('w')) {
        left += dx;
        width -= dx;
      }
      if (drag.handle.includes('e')) {
        width += dx;
      }
      if (drag.handle.includes('n')) {
        top += dy;
        height -= dy;
      }
      if (drag.handle.includes('s')) {
        height += dy;
      }
    }
    onChange(clampFracRect({ left, top, width, height }));
  };

  const end = () => setDrag(null);

  const pct = (n: number) => `${Math.max(0, Math.min(1, n)) * 100}%`;
  const right = rect.left + rect.width;
  const bottom = rect.top + rect.height;

  return (
    <div
      ref={containerRef}
      className="absolute inset-0"
      onPointerMove={onMove}
      onPointerUp={end}
      onPointerCancel={end}
    >
      {/* Four bands darken everything outside the crop rect — simpler and more robust than a clip-path hole. */}
      <div className="pointer-events-none absolute inset-x-0 top-0 bg-black/40" style={{ height: pct(rect.top) }} />
      <div className="pointer-events-none absolute inset-x-0 bottom-0 bg-black/40" style={{ height: pct(1 - bottom) }} />
      <div
        className="pointer-events-none absolute left-0 bg-black/40"
        style={{ top: pct(rect.top), height: pct(rect.height), width: pct(rect.left) }}
      />
      <div
        className="pointer-events-none absolute right-0 bg-black/40"
        style={{ top: pct(rect.top), height: pct(rect.height), width: pct(1 - right) }}
      />
      <div
        onPointerDown={begin('move')}
        className="absolute cursor-move border-2 border-blue-500"
        style={{
          left: `${rect.left * 100}%`,
          top: `${rect.top * 100}%`,
          width: `${rect.width * 100}%`,
          height: `${rect.height * 100}%`,
        }}
      >
        {HANDLES.map((h) => (
          <div
            key={h.id}
            onPointerDown={begin(h.id)}
            className={`absolute h-3 w-3 rounded-full border border-white bg-blue-500 ${h.className}`}
          />
        ))}
      </div>
    </div>
  );
}
