import { useState, type DragEvent, type MouseEvent } from 'react';
import type { PageRef } from '../../lib/pageModel';
import { useDocumentStore } from '../../store/documentStore';
import { useUiStore } from '../../store/uiStore';
import { movePages, rotatePages } from '../../tools/organize/commands';
import { confirmAndDelete, extractPages, runCommand } from '../../tools/organize/actions';
import PageThumbnail from './PageThumbnail';

const THUMB_WIDTH = 108;

interface Menu {
  x: number;
  y: number;
  pageId: string;
}

export default function ThumbnailSidebar() {
  const doc = useDocumentStore((s) => s.doc);
  const selection = useUiStore((s) => s.selection);
  const anchorId = useUiStore((s) => s.anchorId);
  const currentPageId = useUiStore((s) => s.currentPageId);
  const setSelection = useUiStore((s) => s.setSelection);
  const goToPage = useUiStore((s) => s.goToPage);
  const [dragIds, setDragIds] = useState<string[] | null>(null);
  const [dropBeforeId, setDropBeforeId] = useState<string | null | undefined>(undefined);
  const [menu, setMenu] = useState<Menu | null>(null);

  if (!doc) return null;
  const pages = doc.pages;

  const click = (e: MouseEvent, page: PageRef, index: number) => {
    setMenu(null);
    if (e.shiftKey && anchorId) {
      const anchorIdx = pages.findIndex((p) => p.id === anchorId);
      const [from, to] = anchorIdx < index ? [anchorIdx, index] : [index, anchorIdx];
      setSelection(pages.slice(from, to + 1).map((p) => p.id));
    } else if (e.ctrlKey || e.metaKey) {
      const set = new Set(selection);
      if (set.has(page.id)) set.delete(page.id);
      else set.add(page.id);
      setSelection([...set], page.id);
    } else {
      setSelection([page.id], page.id);
    }
    goToPage(page.id, false);
  };

  const selectionOrSelf = (id: string) => (selection.includes(id) ? selection : [id]);

  const onDragStart = (e: DragEvent, page: PageRef) => {
    const ids = selectionOrSelf(page.id);
    setDragIds(ids);
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', page.id);
  };

  const onDragOver = (e: DragEvent, beforeId: string | null) => {
    if (!dragIds) return;
    e.preventDefault();
    setDropBeforeId(beforeId);
  };

  const commitDrop = () => {
    if (dragIds && dropBeforeId !== undefined) runCommand(movePages(dragIds, dropBeforeId));
    setDragIds(null);
    setDropBeforeId(undefined);
  };

  const contextMenuActions = menu
    ? [
        {
          label: selectionOrSelf(menu.pageId).length > 1 ? `Rotate ${selectionOrSelf(menu.pageId).length} pages right` : 'Rotate right',
          onClick: () => runCommand(rotatePages(selectionOrSelf(menu.pageId), 90)),
        },
        { label: 'Rotate left', onClick: () => runCommand(rotatePages(selectionOrSelf(menu.pageId), -90)) },
        {
          label: 'Extract as new PDF',
          onClick: () => void extractPages(selectionOrSelf(menu.pageId).map((id) => pages.findIndex((p) => p.id === id)), 'extract'),
        },
        { label: 'Delete', danger: true, onClick: () => void confirmAndDelete(selectionOrSelf(menu.pageId)) },
      ]
    : [];

  return (
    <div
      className="flex h-full w-40 flex-col overflow-y-auto border-r border-slate-200 bg-slate-50 p-2"
      onClick={() => setMenu(null)}
      onDragLeave={(e) => {
        if (e.currentTarget === e.target) setDropBeforeId(undefined);
      }}
    >
      <p className="mb-2 px-1 text-xs font-medium text-slate-500">
        {pages.length} page{pages.length === 1 ? '' : 's'}
      </p>
      <ol
        className="flex flex-col gap-2"
        onDragOver={(e) => onDragOver(e, null)}
        onDrop={(e) => {
          e.preventDefault();
          commitDrop();
        }}
      >
        {pages.map((page, i) => (
          <li key={page.id} className="relative">
            {dragIds && dropBeforeId === page.id && <div className="absolute -top-1 left-0 right-0 h-0.5 rounded bg-blue-500" />}
            <button
              type="button"
              draggable
              onDragStart={(e) => onDragStart(e, page)}
              onDragOver={(e) => onDragOver(e, page.id)}
              onDragEnd={() => {
                setDragIds(null);
                setDropBeforeId(undefined);
              }}
              onDrop={(e) => {
                e.preventDefault();
                e.stopPropagation();
                commitDrop();
              }}
              onClick={(e) => click(e, page, i)}
              onContextMenu={(e) => {
                e.preventDefault();
                if (!selection.includes(page.id)) setSelection([page.id], page.id);
                setMenu({ x: e.clientX, y: e.clientY, pageId: page.id });
              }}
              aria-current={page.id === currentPageId}
              aria-selected={selection.includes(page.id)}
              className={`flex w-full flex-col items-center gap-1 rounded-lg p-1.5 outline-none ${
                selection.includes(page.id) ? 'bg-blue-100 ring-2 ring-blue-500' : 'hover:bg-slate-200'
              } ${dragIds?.includes(page.id) ? 'opacity-40' : ''}`}
            >
              <PageThumbnail page={page} sources={doc.sources} widthPx={THUMB_WIDTH} />
              <span className="text-xs text-slate-500">{i + 1}</span>
            </button>
          </li>
        ))}
        <li
          className="h-3"
          onDragOver={(e) => onDragOver(e, null)}
          onDrop={(e) => {
            e.preventDefault();
            commitDrop();
          }}
        />
      </ol>

      {menu && (
        <div
          className="fixed z-50 min-w-[10rem] rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
          style={{ left: menu.x, top: menu.y }}
          onClick={(e) => e.stopPropagation()}
        >
          {contextMenuActions.map((action) => (
            <button
              key={action.label}
              type="button"
              onClick={() => {
                action.onClick();
                setMenu(null);
              }}
              className={`block w-full px-3 py-1.5 text-left text-sm hover:bg-slate-100 ${action.danger ? 'text-red-600' : 'text-slate-700'}`}
            >
              {action.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
