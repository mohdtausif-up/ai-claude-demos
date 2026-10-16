import { useRef, useState, type DragEvent } from 'react';
import { openFiles } from '../../tools/organize/actions';
import { TOOL_CATEGORIES } from './toolCatalog';

interface Props {
  onOpened: () => void;
}

/** Drag-and-drop landing page with tool cards grouped by category (PRD §8). */
export default function Landing({ onOpened }: Props) {
  const [dragOver, setDragOver] = useState(false);
  const [busy, setBusy] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const handleFiles = async (files: File[]) => {
    if (files.length === 0 || busy) return;
    setBusy(true);
    try {
      if (await openFiles(files)) onOpened();
    } finally {
      setBusy(false);
    }
  };

  const onDrop = (e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    setDragOver(false);
    void handleFiles([...e.dataTransfer.files]);
  };

  return (
    <div className="mx-auto max-w-5xl px-4 py-10">
      <header className="text-center">
        <h1 className="text-3xl font-bold text-slate-900">ClientPDF</h1>
        <p className="mt-2 text-slate-600">Merge, split, edit and organize PDFs — entirely in your browser.</p>
      </header>

      <div
        role="button"
        tabIndex={0}
        onClick={() => inputRef.current?.click()}
        onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && inputRef.current?.click()}
        onDragOver={(e) => {
          e.preventDefault();
          setDragOver(true);
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={onDrop}
        aria-label="Upload PDF files"
        aria-busy={busy}
        className={`mt-8 flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed p-12 text-center transition-colors ${
          dragOver ? 'border-blue-500 bg-blue-50' : 'border-slate-300 bg-white hover:border-blue-400'
        }`}
      >
        <span className="text-4xl" aria-hidden="true">
          📄
        </span>
        <p className="mt-3 text-lg font-medium text-slate-800">{busy ? 'Opening…' : 'Drop PDF files here, or click to browse'}</p>
        <p className="mt-1 text-sm text-slate-500">Multiple files are merged into one document, in the order you select them.</p>
        <input
          ref={inputRef}
          type="file"
          data-testid="file-input"
          aria-label="Choose PDF files"
          accept="application/pdf,.pdf"
          multiple
          className="hidden"
          onChange={(e) => void handleFiles([...(e.target.files ?? [])]).then(() => (e.target.value = ''))}
        />
      </div>

      <div className="mt-12 space-y-8">
        {TOOL_CATEGORIES.map((cat) => (
          <section key={cat.id}>
            <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">{cat.label}</h2>
            <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
              {cat.tools.map((tool) => (
                <button
                  key={tool.id}
                  type="button"
                  disabled={!tool.available}
                  onClick={() => inputRef.current?.click()}
                  title={tool.description}
                  className={`flex flex-col items-start gap-1 rounded-xl border p-3 text-left transition-colors ${
                    tool.available
                      ? 'border-slate-200 bg-white hover:border-blue-400 hover:shadow-sm'
                      : 'cursor-not-allowed border-slate-100 bg-slate-50 opacity-60'
                  }`}
                >
                  <span className="text-2xl" aria-hidden="true">
                    {tool.icon}
                  </span>
                  <span className="text-sm font-medium text-slate-800">{tool.label}</span>
                  <span className="text-xs text-slate-500">{tool.description}</span>
                </button>
              ))}
            </div>
          </section>
        ))}
      </div>
    </div>
  );
}
