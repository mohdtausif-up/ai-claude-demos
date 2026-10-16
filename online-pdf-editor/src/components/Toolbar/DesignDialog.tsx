import { useState } from 'react';
import type { DesignSettings, DesignPageSelector, DesignPosition } from '../../lib/design';

interface Props {
  settings: DesignSettings;
  onCancel: () => void;
  onConfirm: (settings: DesignSettings) => void;
}

const POSITIONS: DesignPosition[] = ['top-left', 'top-center', 'top-right', 'bottom-left', 'bottom-center', 'bottom-right'];

/** Document-wide watermark/page-number/header-footer/background settings — applied to every export. */
export default function DesignDialog({ settings, onCancel, onConfirm }: Props) {
  const [draft, setDraft] = useState<DesignSettings>(settings);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <div className="max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-5 shadow-xl">
        <h2 className="text-lg font-semibold text-slate-900">Design</h2>
        <p className="mt-1 text-xs text-slate-500">Applied to the whole document whenever you download the PDF.</p>

        <Section title="Watermark" enabled={draft.watermark.enabled} onToggle={(enabled) => setDraft({ ...draft, watermark: { ...draft.watermark, enabled } })}>
          <TextField label="Text" value={draft.watermark.text} onChange={(text) => setDraft({ ...draft, watermark: { ...draft.watermark, text } })} />
          <div className="flex flex-wrap gap-3">
            <NumberField label="Size" value={draft.watermark.fontSize} min={6} max={200} onChange={(fontSize) => setDraft({ ...draft, watermark: { ...draft.watermark, fontSize } })} />
            <ColorField label="Color" value={draft.watermark.color} onChange={(color) => setDraft({ ...draft, watermark: { ...draft.watermark, color } })} />
            <RangeField label="Opacity" value={draft.watermark.opacity} min={0.05} max={1} step={0.05} onChange={(opacity) => setDraft({ ...draft, watermark: { ...draft.watermark, opacity } })} />
            <NumberField label="Rotation°" value={draft.watermark.rotation} min={-180} max={180} onChange={(rotation) => setDraft({ ...draft, watermark: { ...draft.watermark, rotation } })} />
          </div>
          <PageSelectorField name="watermark-pages" value={draft.watermark.pages} onChange={(pages) => setDraft({ ...draft, watermark: { ...draft.watermark, pages } })} />
        </Section>

        <Section title="Page numbers" enabled={draft.pageNumbers.enabled} onToggle={(enabled) => setDraft({ ...draft, pageNumbers: { ...draft.pageNumbers, enabled } })}>
          <TextField label="Format" value={draft.pageNumbers.format} onChange={(format) => setDraft({ ...draft, pageNumbers: { ...draft.pageNumbers, format } })} hint="Use {page}, {total}, {filename}, {date}." />
          <div className="flex flex-wrap gap-3">
            <label className="flex items-center gap-1.5 text-sm">
              <span className="text-slate-600">Position</span>
              <select
                value={draft.pageNumbers.position}
                onChange={(e) => setDraft({ ...draft, pageNumbers: { ...draft.pageNumbers, position: e.target.value as DesignPosition } })}
                className="rounded border border-slate-200 px-1.5 py-1"
              >
                {POSITIONS.map((p) => (
                  <option key={p} value={p}>
                    {p.replace('-', ' ')}
                  </option>
                ))}
              </select>
            </label>
            <NumberField label="Size" value={draft.pageNumbers.fontSize} min={6} max={48} onChange={(fontSize) => setDraft({ ...draft, pageNumbers: { ...draft.pageNumbers, fontSize } })} />
            <ColorField label="Color" value={draft.pageNumbers.color} onChange={(color) => setDraft({ ...draft, pageNumbers: { ...draft.pageNumbers, color } })} />
            <NumberField label="Start at" value={draft.pageNumbers.startAt} min={0} max={9999} onChange={(startAt) => setDraft({ ...draft, pageNumbers: { ...draft.pageNumbers, startAt } })} />
          </div>
          <PageSelectorField name="pagenumbers-pages" value={draft.pageNumbers.pages} onChange={(pages) => setDraft({ ...draft, pageNumbers: { ...draft.pageNumbers, pages } })} />
        </Section>

        <Section title="Header & footer" enabled={draft.headerFooter.enabled} onToggle={(enabled) => setDraft({ ...draft, headerFooter: { ...draft.headerFooter, enabled } })}>
          <p className="text-xs font-medium text-slate-600">Header (left / center / right)</p>
          <div className="grid grid-cols-3 gap-2">
            <MiniField value={draft.headerFooter.headerLeft} onChange={(headerLeft) => setDraft({ ...draft, headerFooter: { ...draft.headerFooter, headerLeft } })} />
            <MiniField value={draft.headerFooter.headerCenter} onChange={(headerCenter) => setDraft({ ...draft, headerFooter: { ...draft.headerFooter, headerCenter } })} />
            <MiniField value={draft.headerFooter.headerRight} onChange={(headerRight) => setDraft({ ...draft, headerFooter: { ...draft.headerFooter, headerRight } })} />
          </div>
          <p className="text-xs font-medium text-slate-600">Footer (left / center / right)</p>
          <div className="grid grid-cols-3 gap-2">
            <MiniField value={draft.headerFooter.footerLeft} onChange={(footerLeft) => setDraft({ ...draft, headerFooter: { ...draft.headerFooter, footerLeft } })} />
            <MiniField value={draft.headerFooter.footerCenter} onChange={(footerCenter) => setDraft({ ...draft, headerFooter: { ...draft.headerFooter, footerCenter } })} />
            <MiniField value={draft.headerFooter.footerRight} onChange={(footerRight) => setDraft({ ...draft, headerFooter: { ...draft.headerFooter, footerRight } })} />
          </div>
          <p className="text-xs text-slate-500">Use {'{page}'}, {'{total}'}, {'{filename}'}, {'{date}'} in any slot.</p>
          <div className="flex flex-wrap gap-3">
            <NumberField label="Size" value={draft.headerFooter.fontSize} min={6} max={36} onChange={(fontSize) => setDraft({ ...draft, headerFooter: { ...draft.headerFooter, fontSize } })} />
            <ColorField label="Color" value={draft.headerFooter.color} onChange={(color) => setDraft({ ...draft, headerFooter: { ...draft.headerFooter, color } })} />
          </div>
          <PageSelectorField name="headerfooter-pages" value={draft.headerFooter.pages} onChange={(pages) => setDraft({ ...draft, headerFooter: { ...draft.headerFooter, pages } })} />
        </Section>

        <Section title="Background color" enabled={draft.background.enabled} onToggle={(enabled) => setDraft({ ...draft, background: { ...draft.background, enabled } })}>
          <ColorField label="Color" value={draft.background.color} onChange={(color) => setDraft({ ...draft, background: { ...draft.background, color } })} />
          <p className="text-xs text-slate-500">Pages with pre-existing links/annotations from the original file lose them where a background is applied — this app's own links/notes are unaffected.</p>
          <PageSelectorField name="background-pages" value={draft.background.pages} onChange={(pages) => setDraft({ ...draft, background: { ...draft.background, pages } })} />
        </Section>

        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onCancel} className="rounded-md px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100">
            Cancel
          </button>
          <button type="button" onClick={() => onConfirm(draft)} className="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700">
            Apply
          </button>
        </div>
      </div>
    </div>
  );
}

function Section({ title, enabled, onToggle, children }: { title: string; enabled: boolean; onToggle: (v: boolean) => void; children: React.ReactNode }) {
  return (
    <div className="mt-4 rounded-lg border border-slate-200 p-3">
      <label className="flex items-center gap-2 text-sm font-semibold text-slate-800">
        <input type="checkbox" checked={enabled} onChange={(e) => onToggle(e.target.checked)} />
        {title}
      </label>
      {enabled && <div className="mt-3 space-y-2">{children}</div>}
    </div>
  );
}

function TextField({ label, value, onChange, hint }: { label: string; value: string; onChange: (v: string) => void; hint?: string }) {
  return (
    <label className="block text-sm">
      <span className="text-slate-600">{label}</span>
      <input value={value} onChange={(e) => onChange(e.target.value)} className="mt-1 block w-full rounded-md border border-slate-300 px-2 py-1" />
      {hint && <span className="mt-0.5 block text-xs text-slate-500">{hint}</span>}
    </label>
  );
}

function MiniField({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  return <input value={value} onChange={(e) => onChange(e.target.value)} className="w-full rounded-md border border-slate-300 px-2 py-1 text-sm" />;
}

function NumberField({ label, value, min, max, onChange }: { label: string; value: number; min: number; max: number; onChange: (v: number) => void }) {
  return (
    <label className="flex items-center gap-1.5 text-sm">
      <span className="text-slate-600">{label}</span>
      <input type="number" value={value} min={min} max={max} onChange={(e) => onChange(Number(e.target.value) || min)} className="w-16 rounded border border-slate-200 px-1.5 py-1" />
    </label>
  );
}

function ColorField({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <label className="flex items-center gap-1.5 text-sm">
      <span className="text-slate-600">{label}</span>
      <input type="color" value={value} onChange={(e) => onChange(e.target.value)} className="h-7 w-8 cursor-pointer rounded border border-slate-200" />
    </label>
  );
}

function RangeField({ label, value, min, max, step, onChange }: { label: string; value: number; min: number; max: number; step: number; onChange: (v: number) => void }) {
  return (
    <label className="flex items-center gap-1.5 text-sm">
      <span className="text-slate-600">{label}</span>
      <input type="range" min={min} max={max} step={step} value={value} onChange={(e) => onChange(Number(e.target.value))} />
    </label>
  );
}

function PageSelectorField({ name, value, onChange }: { name: string; value: DesignPageSelector; onChange: (v: DesignPageSelector) => void }) {
  return (
    <div className="flex items-center gap-2 text-sm">
      <label className="flex items-center gap-1.5">
        <input type="radio" name={name} checked={value.mode === 'all'} onChange={() => onChange({ mode: 'all' })} />
        All pages
      </label>
      <label className="flex items-center gap-1.5">
        <input
          type="radio"
          name={name}
          checked={value.mode === 'custom'}
          onChange={() => onChange({ mode: 'custom', range: value.mode === 'custom' ? value.range : '' })}
        />
        Pages
      </label>
      {value.mode === 'custom' && (
        <input
          value={value.range}
          onChange={(e) => onChange({ mode: 'custom', range: e.target.value })}
          placeholder="e.g. 1-3, 5"
          className="w-32 rounded border border-slate-300 px-2 py-1"
        />
      )}
    </div>
  );
}
