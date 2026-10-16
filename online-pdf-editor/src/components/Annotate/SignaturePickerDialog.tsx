import { useEffect, useRef, useState } from 'react';

interface Props {
  onCancel: () => void;
  onConfirm: (dataUrl: string) => void;
}

type Mode = 'draw' | 'type' | 'upload';

const PAD_WIDTH = 400;
const PAD_HEIGHT = 160;

/**
 * Captures a visual signature three ways — drawn freehand, typed (rendered
 * to a transparent-background PNG via an offscreen canvas, in a cursive
 * font), or uploaded as an image — and returns it as a data: URL. This is
 * purely a picture that gets baked into the page like any other flattened
 * annotation; see lib/annotations.ts's SignatureAnnotation doc comment for
 * why it is explicitly NOT a cryptographic digital signature.
 */
export default function SignaturePickerDialog({ onCancel, onConfirm }: Props) {
  const [mode, setMode] = useState<Mode>('draw');
  const [hasDrawing, setHasDrawing] = useState(false);
  const [typedName, setTypedName] = useState('');
  const [uploadedUrl, setUploadedUrl] = useState<string | null>(null);
  const drawCanvasRef = useRef<HTMLCanvasElement>(null);
  const typeCanvasRef = useRef<HTMLCanvasElement>(null);
  const drawingRef = useRef(false);

  const clearDrawing = () => {
    const canvas = drawCanvasRef.current;
    const ctx = canvas?.getContext('2d');
    if (!canvas || !ctx) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    setHasDrawing(false);
  };

  const pointerPos = (canvas: HTMLCanvasElement, e: React.PointerEvent) => {
    const r = canvas.getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
  };

  const onPointerDown = (e: React.PointerEvent<HTMLCanvasElement>) => {
    const canvas = drawCanvasRef.current;
    const ctx = canvas?.getContext('2d');
    if (!canvas || !ctx) return;
    drawingRef.current = true;
    canvas.setPointerCapture(e.pointerId);
    const { x, y } = pointerPos(canvas, e);
    ctx.beginPath();
    ctx.moveTo(x, y);
  };

  const onPointerMove = (e: React.PointerEvent<HTMLCanvasElement>) => {
    if (!drawingRef.current) return;
    const canvas = drawCanvasRef.current;
    const ctx = canvas?.getContext('2d');
    if (!canvas || !ctx) return;
    const { x, y } = pointerPos(canvas, e);
    ctx.lineCap = 'round';
    ctx.lineWidth = 2.5;
    ctx.strokeStyle = '#111827';
    ctx.lineTo(x, y);
    ctx.stroke();
    setHasDrawing(true);
  };

  const onPointerUp = () => {
    drawingRef.current = false;
  };

  // Re-render the typed-signature preview whenever the name changes.
  useEffect(() => {
    const canvas = typeCanvasRef.current;
    const ctx = canvas?.getContext('2d');
    if (!canvas || !ctx) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (!typedName.trim()) return;
    ctx.fillStyle = '#111827';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.font = "56px 'Brush Script MT', 'Segoe Script', cursive";
    ctx.fillText(typedName.trim(), canvas.width / 2, canvas.height / 2);
  }, [typedName, mode]);

  const onUploadFile = (file: File | undefined) => {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => setUploadedUrl(reader.result as string);
    reader.readAsDataURL(file);
  };

  const canConfirm = (mode === 'draw' && hasDrawing) || (mode === 'type' && typedName.trim().length > 0) || (mode === 'upload' && !!uploadedUrl);

  const confirm = () => {
    if (mode === 'draw' && drawCanvasRef.current) onConfirm(drawCanvasRef.current.toDataURL('image/png'));
    else if (mode === 'type' && typeCanvasRef.current) onConfirm(typeCanvasRef.current.toDataURL('image/png'));
    else if (mode === 'upload' && uploadedUrl) onConfirm(uploadedUrl);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h2 className="text-lg font-semibold text-slate-900">Sign</h2>
        <p className="mt-1 text-xs text-slate-500">
          This creates a picture of a signature, baked into the page — it is not a cryptographic digital signature.
        </p>

        <div className="mt-3 flex gap-1 rounded-md bg-slate-100 p-1 text-sm">
          {(['draw', 'type', 'upload'] as Mode[]).map((m) => (
            <button
              key={m}
              type="button"
              onClick={() => setMode(m)}
              className={`flex-1 rounded px-2 py-1 capitalize ${mode === m ? 'bg-white font-medium text-slate-900 shadow-sm' : 'text-slate-600'}`}
            >
              {m}
            </button>
          ))}
        </div>

        {mode === 'draw' && (
          <div className="mt-3">
            <canvas
              ref={drawCanvasRef}
              data-testid="signature-pad"
              width={PAD_WIDTH}
              height={PAD_HEIGHT}
              onPointerDown={onPointerDown}
              onPointerMove={onPointerMove}
              onPointerUp={onPointerUp}
              className="w-full touch-none rounded-md border border-slate-300 bg-white"
              style={{ height: PAD_HEIGHT }}
            />
            <button type="button" onClick={clearDrawing} className="mt-2 text-xs font-medium text-slate-500 hover:text-slate-700">
              Clear
            </button>
          </div>
        )}

        {mode === 'type' && (
          <div className="mt-3">
            <input
              autoFocus
              value={typedName}
              onChange={(e) => setTypedName(e.target.value)}
              placeholder="Your name"
              className="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm"
            />
            <canvas ref={typeCanvasRef} width={PAD_WIDTH} height={PAD_HEIGHT} className="mt-2 w-full rounded-md border border-slate-200 bg-white" style={{ height: PAD_HEIGHT }} />
          </div>
        )}

        {mode === 'upload' && (
          <div className="mt-3">
            <input type="file" accept="image/png,image/jpeg" onChange={(e) => onUploadFile(e.target.files?.[0])} className="block w-full text-sm" />
            {uploadedUrl && (
              <div className="mt-2 flex h-40 items-center justify-center rounded-md border border-slate-200 bg-white">
                <img src={uploadedUrl} alt="Uploaded signature preview" className="max-h-full max-w-full object-contain" />
              </div>
            )}
          </div>
        )}

        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onCancel} className="rounded-md px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100">
            Cancel
          </button>
          <button
            type="button"
            disabled={!canConfirm}
            onClick={confirm}
            className="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            Use signature
          </button>
        </div>
      </div>
    </div>
  );
}
