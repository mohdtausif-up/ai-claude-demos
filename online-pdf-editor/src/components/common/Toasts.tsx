import { useUiStore } from '../../store/uiStore';

const STYLES = {
  error: 'bg-red-600',
  success: 'bg-emerald-600',
  info: 'bg-slate-700',
} as const;

export default function Toasts() {
  const toasts = useUiStore((s) => s.toasts);
  const dismiss = useUiStore((s) => s.dismissToast);

  return (
    <div className="pointer-events-none fixed bottom-4 left-1/2 z-50 flex -translate-x-1/2 flex-col gap-2" aria-live="polite">
      {toasts.map((t) => (
        <div
          key={t.id}
          role={t.kind === 'error' ? 'alert' : 'status'}
          className={`pointer-events-auto flex max-w-md items-start gap-3 rounded-lg px-4 py-2.5 text-sm text-white shadow-lg ${STYLES[t.kind]}`}
        >
          <span className="flex-1">{t.message}</span>
          <button type="button" onClick={() => dismiss(t.id)} className="shrink-0 opacity-80 hover:opacity-100" aria-label="Dismiss">
            ✕
          </button>
        </div>
      ))}
    </div>
  );
}
