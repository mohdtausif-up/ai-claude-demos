import { useState } from 'react';
import { PAPER_SIZES, type Size } from '../../lib/pageModel';

interface Props {
  onCancel: () => void;
  onConfirm: (size: Size) => void;
}

/** Preset paper sizes plus a custom width/height in millimeters. */
export default function ResizeDialog({ onCancel, onConfirm }: Props) {
  const [preset, setPreset] = useState<keyof typeof PAPER_SIZES | 'custom'>('A4');
  const [mmWidth, setMmWidth] = useState('210');
  const [mmHeight, setMmHeight] = useState('297');

  const size: Size | null =
    preset === 'custom'
      ? (() => {
          const w = Number(mmWidth);
          const h = Number(mmHeight);
          return w > 0 && h > 0 ? { width: (w / 25.4) * 72, height: (h / 25.4) * 72 } : null;
        })()
      : PAPER_SIZES[preset];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <div className="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl">
        <h2 className="text-lg font-semibold text-slate-900">Resize pages</h2>
        <div className="mt-3 grid grid-cols-2 gap-1.5">
          {[...Object.keys(PAPER_SIZES), 'custom'].map((p) => (
            <button
              key={p}
              type="button"
              onClick={() => setPreset(p as keyof typeof PAPER_SIZES | 'custom')}
              className={`rounded-md border px-2 py-1.5 text-sm capitalize ${
                preset === p ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-slate-200 text-slate-700 hover:bg-slate-50'
              }`}
            >
              {p}
            </button>
          ))}
        </div>
        {preset === 'custom' && (
          <div className="mt-3 flex items-center gap-2 text-sm">
            <input value={mmWidth} onChange={(e) => setMmWidth(e.target.value)} className="w-20 rounded border border-slate-300 px-2 py-1" />
            <span>×</span>
            <input value={mmHeight} onChange={(e) => setMmHeight(e.target.value)} className="w-20 rounded border border-slate-300 px-2 py-1" />
            <span className="text-slate-500">mm</span>
          </div>
        )}
        <p className="mt-2 text-xs text-slate-500">Content is scaled to fit the new page size and centered.</p>
        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onCancel} className="rounded-md px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100">
            Cancel
          </button>
          <button
            type="button"
            disabled={!size}
            onClick={() => size && onConfirm(size)}
            className="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            Apply
          </button>
        </div>
      </div>
    </div>
  );
}
