import { useEffect, useRef, useState } from 'react';
import { useUiStore } from '../../store/uiStore';

/** Asks for a PDF's password (opening an encrypted file) — see uiStore.askPassword. */
export default function PasswordPromptDialog() {
  const req = useUiStore((s) => s.passwordPrompt);
  const resolvePassword = useUiStore((s) => s.resolvePassword);
  const [value, setValue] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (req) {
      setValue('');
      inputRef.current?.focus();
    }
  }, [req]);

  if (!req) return null;

  const submit = () => resolvePassword(value);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onKeyDown={(e) => e.key === 'Escape' && resolvePassword(null)}>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          submit();
        }}
        className="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl"
      >
        <h2 className="text-lg font-semibold text-slate-900">{req.title}</h2>
        <p className="mt-2 text-sm text-slate-600">{req.message}</p>
        {req.error && <p className="mt-2 text-sm text-red-600">{req.error}</p>}
        <input
          ref={inputRef}
          type="password"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          className="mt-3 block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm"
          placeholder="Password"
        />
        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={() => resolvePassword(null)} className="rounded-md px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100">
            Cancel
          </button>
          <button type="submit" className="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700">
            Unlock
          </button>
        </div>
      </form>
    </div>
  );
}
