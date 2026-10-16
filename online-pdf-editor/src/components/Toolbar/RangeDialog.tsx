import { useState, type FormEvent } from 'react';

interface Props {
  title: string;
  label: string;
  placeholder: string;
  hint: string;
  confirmLabel: string;
  onCancel: () => void;
  onConfirm: (value: string) => void;
}

/** Small modal for entering a page-range or split-every-N string. */
export default function RangeDialog({ title, label, placeholder, hint, confirmLabel, onCancel, onConfirm }: Props) {
  const [value, setValue] = useState('');

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (value.trim()) onConfirm(value.trim());
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form onSubmit={submit} className="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl">
        <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
        <label className="mt-3 block text-sm font-medium text-slate-700">
          {label}
          {/* eslint-disable-next-line jsx-a11y/no-autofocus */}
          <input
            autoFocus
            value={value}
            onChange={(e) => setValue(e.target.value)}
            placeholder={placeholder}
            className="mt-1 block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm"
          />
        </label>
        <p className="mt-1 text-xs text-slate-500">{hint}</p>
        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onCancel} className="rounded-md px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100">
            Cancel
          </button>
          <button type="submit" disabled={!value.trim()} className="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
            {confirmLabel}
          </button>
        </div>
      </form>
    </div>
  );
}
