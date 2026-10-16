import { useEffect } from 'react';
import { useAnnotateStore } from '../store/annotateStore';
import { useDocumentStore } from '../store/documentStore';
import { useUiStore } from '../store/uiStore';
import { removeAnnotation } from '../tools/edit/commands';
import { confirmAndDelete, runCommand } from '../tools/organize/actions';
import AnnotateToolbar from './Toolbar/AnnotateToolbar';
import Toolbar from './Toolbar/Toolbar';
import ThumbnailSidebar from './Viewer/ThumbnailSidebar';
import Viewer from './Viewer/Viewer';

function isEditableTarget(el: EventTarget | null): boolean {
  const tag = (el as HTMLElement | null)?.tagName;
  return tag === 'INPUT' || tag === 'TEXTAREA' || (el as HTMLElement | null)?.isContentEditable === true;
}

/** Global keyboard shortcuts: undo/redo, delete, page navigation (PRD §6 accessibility groundwork). */
function useEditorShortcuts() {
  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => {
      if (isEditableTarget(e.target)) return;
      const mod = e.ctrlKey || e.metaKey;
      if (mod && e.key.toLowerCase() === 'z' && !e.shiftKey) {
        e.preventDefault();
        useDocumentStore.getState().undo();
      } else if (mod && (e.key.toLowerCase() === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey))) {
        e.preventDefault();
        useDocumentStore.getState().redo();
      } else if (e.key === 'Delete' || e.key === 'Backspace') {
        // An annotation selection takes priority over a page-thumbnail
        // selection — they're independent, but only one is what the user is
        // looking at when they press Delete.
        const annotationId = useAnnotateStore.getState().selectedId;
        const currentPageId = useUiStore.getState().currentPageId;
        if (annotationId && currentPageId) {
          e.preventDefault();
          runCommand(removeAnnotation(currentPageId, annotationId));
          return;
        }
        const selection = useUiStore.getState().selection;
        if (selection.length > 0) {
          e.preventDefault();
          void confirmAndDelete(selection);
        }
      } else if (e.key === 'Escape') {
        useUiStore.getState().setTool('organize');
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, []);
}

export default function Editor() {
  useEditorShortcuts();
  const activeTool = useUiStore((s) => s.activeTool);
  return (
    <div className="flex h-full min-h-0 flex-1 flex-col">
      <Toolbar />
      {activeTool === 'annotate' && <AnnotateToolbar />}
      <div className="flex min-h-0 flex-1">
        <ThumbnailSidebar />
        <Viewer />
      </div>
    </div>
  );
}
