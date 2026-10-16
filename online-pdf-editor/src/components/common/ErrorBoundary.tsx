import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
  children: ReactNode;
}

interface State {
  error: Error | null;
}

/** Catches render crashes so one broken tool can't blank the whole app. */
export default class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('Unhandled UI error:', error, info.componentStack);
  }

  render() {
    if (this.state.error) {
      return (
        <div role="alert" className="m-4 rounded-lg border border-red-300 bg-red-50 p-6 text-red-900">
          <h2 className="font-semibold">Something went wrong.</h2>
          <p className="mt-1 text-sm">
            This tool hit an unexpected error. Your file was never uploaded anywhere, so it's safe to reload and try again.
          </p>
          <button
            type="button"
            onClick={() => location.reload()}
            className="mt-3 rounded bg-red-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-800"
          >
            Reload the app
          </button>
        </div>
      );
    }
    return this.props.children;
  }
}
