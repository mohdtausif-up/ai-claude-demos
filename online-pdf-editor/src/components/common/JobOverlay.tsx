import { useUiStore } from '../../store/uiStore';

/** Progress indicator with cancel, shown for any operation the PRD calls out as >1s. */
export default function JobOverlay() {
  const job = useUiStore((s) => s.job);
  if (!job) return null;

  const pct = job.progress == null ? null : Math.round(job.progress * 100);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" role="status" aria-live="polite">
      <div className="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl">
        <p className="text-sm font-medium text-slate-900">{job.detail ?? job.label}</p>
        <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
          <div
            className={`h-full rounded-full bg-blue-600 transition-all ${pct == null ? 'w-1/3 animate-pulse' : ''}`}
            style={pct == null ? undefined : { width: `${pct}%` }}
          />
        </div>
        <div className="mt-3 flex items-center justify-between">
          <span className="text-xs text-slate-500">{pct == null ? 'Working…' : `${pct}%`}</span>
          {job.cancel && (
            <button type="button" onClick={job.cancel} className="text-xs font-medium text-slate-600 hover:text-slate-900">
              Cancel
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
