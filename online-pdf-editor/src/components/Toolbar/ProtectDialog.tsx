import { useState } from 'react';
import type { PermissionLevel, PrintLevel, ProtectOptions } from '../../lib/qpdfClient';

interface Props {
  onCancel: () => void;
  onConfirm: (opts: ProtectOptions) => void;
}

const PRINT_OPTIONS: { value: PrintLevel; label: string }[] = [
  { value: 'full', label: 'Allowed' },
  { value: 'low', label: 'Low resolution only' },
  { value: 'none', label: 'Not allowed' },
];

const MODIFY_OPTIONS: { value: PermissionLevel; label: string }[] = [
  { value: 'all', label: 'Any changes' },
  { value: 'form', label: 'Filling forms & commenting only' },
  { value: 'none', label: 'No changes' },
];

/** Sets a password (and permissions) on the exported PDF via qpdf (see lib/qpdfClient.ts). */
export default function ProtectDialog({ onCancel, onConfirm }: Props) {
  const [userPassword, setUserPassword] = useState('');
  const [ownerPassword, setOwnerPassword] = useState('');
  const [print, setPrint] = useState<PrintLevel>('full');
  const [modify, setModify] = useState<PermissionLevel>('all');
  const [allowCopy, setAllowCopy] = useState(true);

  const canSubmit = userPassword.trim().length > 0;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <div className="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl">
        <h2 className="text-lg font-semibold text-slate-900">Protect PDF</h2>
        <p className="mt-1 text-xs text-slate-500">
          Encrypts a downloaded copy with a password (AES-256, via a vendored qpdf WASM build — see{' '}
          <code>public/qpdf/PROVENANCE.md</code>). The document open in the editor is unaffected.
        </p>

        <label className="mt-3 block text-sm">
          <span className="text-slate-600">Password to open the file</span>
          <input
            type="password"
            value={userPassword}
            onChange={(e) => setUserPassword(e.target.value)}
            className="mt-1 block w-full rounded-md border border-slate-300 px-2 py-1"
            autoFocus
          />
        </label>
        <label className="mt-2 block text-sm">
          <span className="text-slate-600">Password to change permissions (optional)</span>
          <input
            type="password"
            value={ownerPassword}
            onChange={(e) => setOwnerPassword(e.target.value)}
            placeholder="Defaults to the password above"
            className="mt-1 block w-full rounded-md border border-slate-300 px-2 py-1"
          />
        </label>

        <label className="mt-3 block text-sm">
          <span className="text-slate-600">Printing</span>
          <select value={print} onChange={(e) => setPrint(e.target.value as PrintLevel)} className="mt-1 block w-full rounded-md border border-slate-300 px-2 py-1">
            {PRINT_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </label>
        <label className="mt-2 block text-sm">
          <span className="text-slate-600">Modifying</span>
          <select value={modify} onChange={(e) => setModify(e.target.value as PermissionLevel)} className="mt-1 block w-full rounded-md border border-slate-300 px-2 py-1">
            {MODIFY_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </label>
        <label className="mt-2 flex items-center gap-1.5 text-sm">
          <input type="checkbox" checked={allowCopy} onChange={(e) => setAllowCopy(e.target.checked)} />
          Allow copying text and images
        </label>

        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onCancel} className="rounded-md px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100">
            Cancel
          </button>
          <button
            type="button"
            disabled={!canSubmit}
            onClick={() => onConfirm({ userPassword: userPassword.trim(), ownerPassword: ownerPassword.trim(), print, modify, allowCopy })}
            className="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            Protect & download
          </button>
        </div>
      </div>
    </div>
  );
}
