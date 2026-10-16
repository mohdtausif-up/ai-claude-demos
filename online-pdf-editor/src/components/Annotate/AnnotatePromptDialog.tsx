import { useState, type FormEvent } from 'react';

interface Props {
  kind: 'link' | 'note';
  onCancel: () => void;
  onSubmit: (value: string) => void;
}

/** Small modal asking for a link URL or sticky-note body right after it's placed. */
export default function AnnotatePromptDialog({ kind, onCancel, onSubmit }: Props) {
  const [value, setValue] = useState('');

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const trimmed = value.trim();
    if (!trimmed) return;
    onSubmit(kind === 'link' ? withProtocol(trimmed) : trimmed);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form onSubmit={submit} className="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl">
        <h2 className="text-lg font-semibold text-slate-900">{kind === 'link' ? 'Add link' : 'Add note'}</h2>
        {kind === 'link' ? (
          // eslint-disable-next-line jsx-a11y/no-autofocus
          <input
            autoFocus
            value={value}
            onChange={(e) => setValue(e.target.value)}
            placeholder="https://example.com"
            // type="text", not "url": we normalize scheme-less input ourselves
            // (withProtocol below) — native URL validation would block submit
            // before that ever runs, defeating the point.
            type="text"
            className="mt-3 block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm"
          />
        ) : (
          // eslint-disable-next-line jsx-a11y/no-autofocus
          <textarea
            autoFocus
            value={value}
            onChange={(e) => setValue(e.target.value)}
            placeholder="Note text…"
            rows={3}
            className="mt-3 block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm"
          />
        )}
        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onCancel} className="rounded-md px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100">
            Cancel
          </button>
          <button type="submit" disabled={!value.trim()} className="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
            Add
          </button>
        </div>
      </form>
    </div>
  );
}

function withProtocol(url: string): string {
  return /^[a-z][a-z0-9+.-]*:/i.test(url) ? url : `https://${url}`;
}
