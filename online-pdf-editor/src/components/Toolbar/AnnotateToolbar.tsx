import { STANDARD_FONT_FAMILIES, type TextAlign } from '../../lib/annotations';
import { useAnnotateStore, type AnnotateTool } from '../../store/annotateStore';
import { useUiStore } from '../../store/uiStore';

const TOOLS: { id: AnnotateTool; label: string; icon: string; hint: string }[] = [
  { id: 'select', label: 'Select', icon: '↖', hint: 'Move, resize or delete existing annotations' },
  { id: 'text', label: 'Text', icon: 'T', hint: 'Click to add a text box' },
  { id: 'rectangle', label: 'Rectangle', icon: '▭', hint: 'Drag to draw a rectangle' },
  { id: 'ellipse', label: 'Ellipse', icon: '◯', hint: 'Drag to draw an ellipse' },
  { id: 'line', label: 'Line', icon: '╱', hint: 'Drag to draw a line' },
  { id: 'arrow', label: 'Arrow', icon: '→', hint: 'Drag to draw an arrow' },
  { id: 'draw', label: 'Draw', icon: '✎', hint: 'Freehand pen' },
  { id: 'whiteout', label: 'Whiteout', icon: '▮', hint: 'Cover content with an opaque white box' },
  { id: 'redact', label: 'Redact', icon: '■', hint: 'Permanently or visually hide sensitive content' },
  { id: 'highlight', label: 'Highlight', icon: '🖍', hint: 'Drag over text to highlight it' },
  { id: 'underline', label: 'Underline', icon: 'U̲', hint: 'Drag over text to underline it' },
  { id: 'strikethrough', label: 'Strike', icon: 'S̶', hint: 'Drag over text to strike it through' },
  { id: 'link', label: 'Link', icon: '🔗', hint: 'Drag to place a clickable link area' },
  { id: 'note', label: 'Note', icon: '🗨', hint: 'Click to place a sticky note' },
  { id: 'signature', label: 'Signature', icon: '✒️', hint: 'Draw, type or upload a signature' },
];

const ALIGNS: TextAlign[] = ['left', 'center', 'right'];

export default function AnnotateToolbar() {
  const tool = useAnnotateStore((s) => s.tool);
  const setTool = useAnnotateStore((s) => s.setTool);
  const style = useAnnotateStore((s) => s.style);
  const setStyle = useAnnotateStore((s) => s.setStyle);

  return (
    <div className="border-b border-slate-200 bg-white px-3 py-2">
      <div className="flex flex-wrap items-center gap-1">
        {TOOLS.map((t) => (
          <button
            key={t.id}
            type="button"
            title={t.hint}
            onClick={() => setTool(t.id)}
            className={`flex items-center gap-1 rounded px-2 py-1.5 text-sm ${
              tool === t.id ? 'bg-blue-100 font-medium text-blue-700' : 'text-slate-700 hover:bg-slate-100'
            }`}
          >
            <span aria-hidden="true">{t.icon}</span>
            {t.label}
          </button>
        ))}
        <button
          type="button"
          onClick={() => useUiStore.getState().setTool('organize')}
          className="ml-auto rounded px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-100"
        >
          Done
        </button>
      </div>

      <div className="mt-2 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-2 text-sm">
        {tool === 'text' && (
          <>
            <select
              value={style.font.family}
              onChange={(e) => setStyle({ font: { ...style.font, family: e.target.value as (typeof STANDARD_FONT_FAMILIES)[number] } })}
              className="rounded border border-slate-200 px-1.5 py-1"
            >
              {STANDARD_FONT_FAMILIES.map((f) => (
                <option key={f} value={f}>
                  {f}
                </option>
              ))}
            </select>
            <ToggleButton active={style.font.bold} onClick={() => setStyle({ font: { ...style.font, bold: !style.font.bold } })}>
              <b>B</b>
            </ToggleButton>
            <ToggleButton active={style.font.italic} onClick={() => setStyle({ font: { ...style.font, italic: !style.font.italic } })}>
              <i>I</i>
            </ToggleButton>
            <NumberField label="Size" value={style.fontSize} min={6} max={144} onChange={(v) => setStyle({ fontSize: v })} />
            <ColorField label="Color" value={style.textColor} onChange={(v) => setStyle({ textColor: v })} />
            <div className="flex items-center gap-1">
              {ALIGNS.map((a) => (
                <ToggleButton key={a} active={style.align === a} onClick={() => setStyle({ align: a })} title={a}>
                  {a === 'left' ? '⟸' : a === 'center' ? '⟺' : '⟹'}
                </ToggleButton>
              ))}
            </div>
          </>
        )}

        {(tool === 'rectangle' || tool === 'ellipse') && (
          <>
            <label className="flex items-center gap-1">
              <input type="checkbox" checked={style.fillEnabled} onChange={(e) => setStyle({ fillEnabled: e.target.checked })} />
              Fill
            </label>
            {style.fillEnabled && <ColorField label="" value={style.fillColor} onChange={(v) => setStyle({ fillColor: v })} />}
            <label className="flex items-center gap-1">
              <input type="checkbox" checked={style.strokeEnabled} onChange={(e) => setStyle({ strokeEnabled: e.target.checked })} />
              Border
            </label>
            {style.strokeEnabled && (
              <>
                <ColorField label="" value={style.strokeColor} onChange={(v) => setStyle({ strokeColor: v })} />
                <NumberField label="Width" value={style.strokeWidth} min={1} max={20} onChange={(v) => setStyle({ strokeWidth: v })} />
              </>
            )}
            <OpacityField value={style.opacity} onChange={(v) => setStyle({ opacity: v })} />
          </>
        )}

        {(tool === 'line' || tool === 'arrow' || tool === 'draw') && (
          <>
            <ColorField label="Color" value={style.strokeColor} onChange={(v) => setStyle({ strokeColor: v })} />
            <NumberField label="Width" value={style.strokeWidth} min={1} max={20} onChange={(v) => setStyle({ strokeWidth: v })} />
          </>
        )}

        {tool === 'whiteout' && <p className="text-slate-500">Draws an opaque white box. The covered content is still in the file underneath.</p>}

        {tool === 'redact' && (
          <div className="flex flex-1 flex-wrap items-center gap-3 rounded-md bg-red-50 p-2">
            <span className="font-semibold text-red-900">Redaction mode:</span>
            <label className="flex items-center gap-1.5">
              <input type="radio" name="redact-mode" checked={style.redactMode === 'visual'} onChange={() => setStyle({ redactMode: 'visual' })} />
              <span>
                <b>Visual cover</b> — fast, but the hidden text is still in the file
              </span>
            </label>
            <label className="flex items-center gap-1.5">
              <input type="radio" name="redact-mode" checked={style.redactMode === 'true'} onChange={() => setStyle({ redactMode: 'true' })} />
              <span>
                <b>True redact</b> — permanently removes the hidden content (slower)
              </span>
            </label>
            <ColorField label="Color" value={style.redactColor} onChange={(v) => setStyle({ redactColor: v })} />
          </div>
        )}

        {(tool === 'highlight' || tool === 'underline' || tool === 'strikethrough') && (
          <>
            <ColorField label="Color" value={style.markupColor} onChange={(v) => setStyle({ markupColor: v })} />
            <p className="text-slate-500">Drag across text in the page to mark it.</p>
          </>
        )}

        {tool === 'note' && <ColorField label="Color" value={style.noteColor} onChange={(v) => setStyle({ noteColor: v })} />}

        {tool === 'link' && <p className="text-slate-500">Drag to place a link area, then enter the URL.</p>}

        {tool === 'select' && <p className="text-slate-500">Click an annotation to move, resize or delete it (Delete key).</p>}

        {tool === 'signature' && <p className="text-slate-500">Draw, type or upload a signature, then drag it into place.</p>}
      </div>
    </div>
  );
}

function ToggleButton({ active, onClick, title, children }: { active: boolean; onClick: () => void; title?: string; children: React.ReactNode }) {
  return (
    <button
      type="button"
      title={title}
      onClick={onClick}
      className={`rounded px-2 py-1 ${active ? 'bg-blue-100 text-blue-700' : 'text-slate-700 hover:bg-slate-100'}`}
    >
      {children}
    </button>
  );
}

function ColorField({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <label className="flex items-center gap-1.5">
      {label && <span className="text-slate-600">{label}</span>}
      <input type="color" value={value} onChange={(e) => onChange(e.target.value)} className="h-7 w-8 cursor-pointer rounded border border-slate-200" />
    </label>
  );
}

function NumberField({ label, value, min, max, onChange }: { label: string; value: number; min: number; max: number; onChange: (v: number) => void }) {
  return (
    <label className="flex items-center gap-1.5">
      <span className="text-slate-600">{label}</span>
      <input
        type="number"
        value={value}
        min={min}
        max={max}
        onChange={(e) => onChange(Number(e.target.value) || min)}
        className="w-14 rounded border border-slate-200 px-1.5 py-1"
      />
    </label>
  );
}

function OpacityField({ value, onChange }: { value: number; onChange: (v: number) => void }) {
  return (
    <label className="flex items-center gap-1.5">
      <span className="text-slate-600">Opacity</span>
      <input type="range" min={0.1} max={1} step={0.05} value={value} onChange={(e) => onChange(Number(e.target.value))} />
    </label>
  );
}
