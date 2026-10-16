/** Persistent, unmissable per PRD §8 — never dismissible, since it's the core trust claim. */
export default function PrivacyBanner() {
  return (
    <div className="flex items-center justify-center gap-2 bg-emerald-700 px-3 py-1.5 text-center text-sm font-medium text-white">
      <span aria-hidden="true">🔒</span>
      <span>Processed 100% in your browser — files are never uploaded.</span>
    </div>
  );
}
