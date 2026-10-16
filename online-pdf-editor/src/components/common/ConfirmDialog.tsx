import { useEffect, useRef } from 'react';
import { useUiStore } from '../../store/uiStore';

/** Every destructive action routes through here (PRD "quality bar": explicit confirm step). */
export default function ConfirmDialog() {
  const confirm = useUiStore((s) => s.confirm);
  const resolveConfirm = useUiStore((s) => s.resolveConfirm);
  const confirmBtn = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (confirm) confirmBtn.current?.focus();
  }, [confirm]);

  if (!confirm) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onKeyDown={(e) => e.key === 'Escape' && resolveConfirm(false)}
    >
      <div role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" className="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl">
        <h2 id="confirm-title" className="text-lg font-semibold text-slate-900">
          {confirm.title}
        </h2>
        <p className="mt-2 text-sm text-slate-600">{confirm.message}</p>
        <div className="mt-5 flex justify-end gap-2">
          <button
            type="button"
            onClick={() => resolveConfirm(false)}
            className="rounded-md px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100"
          >
            Cancel
          </button>
          <button
            ref={confirmBtn}
            type="button"
            onClick={() => resolveConfirm(true)}
            className={`rounded-md px-3 py-1.5 text-sm font-medium text-white ${
              confirm.destructive ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'
            }`}
          >
            {confirm.confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
