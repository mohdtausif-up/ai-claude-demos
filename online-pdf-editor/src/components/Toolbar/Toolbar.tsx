import { useRef, useState } from 'react';
import { parsePageList, parseRangeGroups, splitEvery } from '../../lib/pageRanges';
import { blankPageRef, PAPER_SIZES, type Size } from '../../lib/pageModel';
import { useDocumentStore } from '../../store/documentStore';
import { useUiStore } from '../../store/uiStore';
import { insertPages, rotatePages, setResizes } from '../../tools/organize/commands';
import { closeDocument, confirmAndDelete, exportDocument, extractPages, insertFiles, reportError, runCommand, splitDocument } from '../../tools/organize/actions';
import { updateDesign } from '../../tools/design/commands';
import { setFlattenForms } from '../../tools/forms/commands';
import { protectAndDownload } from '../../tools/security/actions';
import type { ProtectOptions } from '../../lib/qpdfClient';
import DesignDialog from './DesignDialog';
import ProtectDialog from './ProtectDialog';
import RangeDialog from './RangeDialog';
import ResizeDialog from './ResizeDialog';

type Dialog = 'split-range' | 'split-every' | 'extract' | 'resize' | 'design' | 'protect' | null;

export default function Toolbar() {
  const doc = useDocumentStore((s) => s.doc);
  const fileName = useDocumentStore((s) => s.fileName);
  const past = useDocumentStore((s) => s.past);
  const future = useDocumentStore((s) => s.future);
  const undo = useDocumentStore((s) => s.undo);
  const redo = useDocumentStore((s) => s.redo);
  const selection = useUiStore((s) => s.selection);
  const activeTool = useUiStore((s) => s.activeTool);
  const setTool = useUiStore((s) => s.setTool);
  const currentPageId = useUiStore((s) => s.currentPageId);

  const [dialog, setDialog] = useState<Dialog>(null);
  const [exporting, setExporting] = useState(false);
  const [protecting, setProtecting] = useState(false);
  const insertInputRef = useRef<HTMLInputElement>(null);

  if (!doc) return null;
  const pageCount = doc.pages.length;
  const currentIndex = Math.max(0, doc.pages.findIndex((p) => p.id === currentPageId));
  const targetIds = selection.length > 0 ? selection : currentPageId ? [currentPageId] : [];
  const hasFormFields = doc.pages.some((p) => p.base.fields.length > 0);

  const insertAt = (files: FileList | null, kind: 'file' | 'blank-before' | 'blank-after') => {
    const index = kind === 'blank-after' ? currentIndex + 1 : currentIndex;
    if (kind === 'file') {
      if (files && files.length > 0) void insertFiles([...files], index, 'Insert');
      return;
    }
    runCommand(insertPages([blankPageRef(PAPER_SIZES.A4)], index, [], 'Insert blank page'));
  };

  const doExport = async () => {
    setExporting(true);
    try {
      await exportDocument();
    } finally {
      setExporting(false);
    }
  };

  const doProtect = async (opts: ProtectOptions) => {
    setDialog(null);
    setProtecting(true);
    try {
      await protectAndDownload(opts);
    } finally {
      setProtecting(false);
    }
  };

  return (
    <div className="flex items-center gap-1 overflow-x-auto border-b border-slate-200 bg-white px-3 py-2">
      <button type="button" onClick={() => void closeDocument()} className="rounded px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-100" title="Close document">
        ← Close
      </button>
      <span className="mx-1 max-w-[12rem] truncate text-sm font-medium text-slate-800" title={fileName}>
        {fileName}
      </span>

      <Divider />

      <ToolButton active={activeTool === 'organize'} onClick={() => setTool('organize')}>
        Organize
      </ToolButton>
      <ToolButton active={activeTool === 'annotate'} onClick={() => setTool('annotate')}>
        ✎ Annotate
      </ToolButton>
      <ToolButton
        active={activeTool === 'fill-form'}
        disabled={!hasFormFields}
        title={hasFormFields ? undefined : 'This document has no fillable form fields.'}
        onClick={() => setTool(activeTool === 'fill-form' ? 'organize' : 'fill-form')}
      >
        🗒 Fill form
      </ToolButton>
      {activeTool === 'fill-form' && (
        <label className="flex items-center gap-1.5 whitespace-nowrap text-sm text-slate-700">
          <input type="checkbox" checked={doc.flattenForms} onChange={(e) => runCommand(setFlattenForms(e.target.checked))} />
          Flatten on export
        </label>
      )}
      <ToolButton
        onClick={() => (targetIds.length ? runCommand(rotatePages(targetIds, 90)) : reportError(new Error('Select a page first.')))}
      >
        ⟳ Rotate right
      </ToolButton>
      <ToolButton onClick={() => (targetIds.length ? runCommand(rotatePages(targetIds, -90)) : reportError(new Error('Select a page first.')))}>
        ⟲ Rotate left
      </ToolButton>
      <ToolButton active={activeTool === 'crop'} onClick={() => setTool(activeTool === 'crop' ? 'organize' : 'crop')} disabled={!currentPageId}>
        Crop
      </ToolButton>
      <ToolButton onClick={() => setDialog('resize')}>Resize</ToolButton>
      <ToolButton onClick={() => setDialog('design')}>Design…</ToolButton>
      <ToolButton onClick={() => setDialog('protect')} disabled={protecting}>
        {protecting ? 'Protecting…' : 'Protect…'}
      </ToolButton>
      <ToolButton onClick={() => (targetIds.length ? void confirmAndDelete(targetIds) : reportError(new Error('Select a page first.')))}>
        Delete
      </ToolButton>
      <ToolButton onClick={() => setDialog('extract')}>Extract…</ToolButton>

      <Divider />

      <ToolButton onClick={() => setDialog('split-range')}>Split by range…</ToolButton>
      <ToolButton onClick={() => setDialog('split-every')}>Split every N…</ToolButton>

      <Divider />

      <label className="cursor-pointer rounded px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-100">
        + Insert file
        <input
          ref={insertInputRef}
          type="file"
          accept="application/pdf,.pdf"
          multiple
          className="hidden"
          onChange={(e) => {
            insertAt(e.target.files, 'file');
            e.target.value = '';
          }}
        />
      </label>
      <ToolButton onClick={() => insertAt(null, 'blank-after')}>+ Blank page</ToolButton>

      <div className="flex-1" />

      <ToolButton onClick={undo} disabled={past.length === 0} title="Undo (Ctrl+Z)">
        ↩ Undo
      </ToolButton>
      <ToolButton onClick={redo} disabled={future.length === 0} title="Redo (Ctrl+Y)">
        ↪ Redo
      </ToolButton>

      <Divider />

      <button
        type="button"
        onClick={() => void doExport()}
        disabled={exporting}
        className="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
      >
        {exporting ? 'Saving…' : '⬇ Download PDF'}
      </button>

      {dialog === 'split-range' && (
        <RangeDialog
          title="Split by page ranges"
          label="Ranges"
          placeholder="e.g. 1-3, 4-6, 7"
          hint="Each comma-separated group becomes its own file, downloaded as a ZIP."
          confirmLabel="Split"
          onCancel={() => setDialog(null)}
          onConfirm={(value) => {
            setDialog(null);
            try {
              void splitDocument(parseRangeGroups(value, pageCount));
            } catch (err) {
              reportError(err);
            }
          }}
        />
      )}
      {dialog === 'split-every' && (
        <RangeDialog
          title="Split every N pages"
          label="Pages per file"
          placeholder="e.g. 5"
          hint={`This document has ${pageCount} pages.`}
          confirmLabel="Split"
          onCancel={() => setDialog(null)}
          onConfirm={(value) => {
            setDialog(null);
            try {
              void splitDocument(splitEvery(pageCount, Number(value)));
            } catch (err) {
              reportError(err);
            }
          }}
        />
      )}
      {dialog === 'extract' && (
        <RangeDialog
          title="Extract pages"
          label="Pages"
          placeholder="e.g. 1-3, 5"
          hint={`This document has ${pageCount} pages. All listed pages are saved as one new PDF.`}
          confirmLabel="Extract"
          onCancel={() => setDialog(null)}
          onConfirm={(value) => {
            setDialog(null);
            try {
              void extractPages(parsePageList(value, pageCount));
            } catch (err) {
              reportError(err);
            }
          }}
        />
      )}
      {dialog === 'resize' && (
        <ResizeDialog
          onCancel={() => setDialog(null)}
          onConfirm={(size: Size) => {
            setDialog(null);
            const ids = targetIds.length ? targetIds : doc.pages.map((p) => p.id);
            runCommand(setResizes(Object.fromEntries(ids.map((id) => [id, size]))));
          }}
        />
      )}
      {dialog === 'design' && (
        <DesignDialog
          settings={doc.design}
          onCancel={() => setDialog(null)}
          onConfirm={(settings) => {
            setDialog(null);
            runCommand(updateDesign(settings));
          }}
        />
      )}
      {dialog === 'protect' && <ProtectDialog onCancel={() => setDialog(null)} onConfirm={(opts) => void doProtect(opts)} />}
    </div>
  );
}

function Divider() {
  return <div className="mx-1 h-6 w-px bg-slate-200" />;
}

function ToolButton({
  children,
  active,
  disabled,
  onClick,
  title,
}: {
  children: React.ReactNode;
  active?: boolean;
  disabled?: boolean;
  onClick: () => void;
  title?: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      title={title}
      className={`whitespace-nowrap rounded px-2 py-1.5 text-sm ${
        active ? 'bg-blue-100 font-medium text-blue-700' : 'text-slate-700 hover:bg-slate-100'
      } disabled:cursor-not-allowed disabled:opacity-40`}
    >
      {children}
    </button>
  );
}
