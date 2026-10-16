import { useEffect } from 'react';
import Landing from './components/ToolCards/Landing';
import ConfirmDialog from './components/common/ConfirmDialog';
import ErrorBoundary from './components/common/ErrorBoundary';
import JobOverlay from './components/common/JobOverlay';
import PasswordPromptDialog from './components/common/PasswordPromptDialog';
import PrivacyBanner from './components/common/PrivacyBanner';
import Toasts from './components/common/Toasts';
import Editor from './components/Editor';
import { useDocumentStore } from './store/documentStore';

export default function App() {
  const hasDoc = useDocumentStore((s) => s.doc !== null);

  // Warn before an accidental tab close discards unsaved edits (no data leaves
  // the browser either way — this is purely a "did you mean to?" guard).
  useEffect(() => {
    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      if (useDocumentStore.getState().past.length > 0) e.preventDefault();
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, []);

  return (
    <div className="flex h-screen flex-col">
      <PrivacyBanner />
      <ErrorBoundary>
        <div className="min-h-0 flex-1 overflow-auto">
          {hasDoc ? <Editor /> : <Landing onOpened={() => undefined} />}
        </div>
      </ErrorBoundary>
      <JobOverlay />
      <ConfirmDialog />
      <PasswordPromptDialog />
      <Toasts />
    </div>
  );
}
