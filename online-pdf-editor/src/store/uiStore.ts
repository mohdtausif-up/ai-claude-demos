import { create } from 'zustand';
import type { FracRect } from '../lib/pageModel';

export type ToolId = 'organize' | 'merge' | 'split' | 'extract' | 'insert' | 'rotate' | 'crop' | 'resize' | 'delete' | 'annotate' | 'fill-form';

export interface Job {
  label: string;
  /** 0..1, or null for indeterminate. */
  progress: number | null;
  detail?: string;
  cancel?: () => void;
}

export interface Toast {
  id: number;
  kind: 'error' | 'info' | 'success';
  message: string;
}

export interface ConfirmRequest {
  title: string;
  message: string;
  confirmLabel: string;
  destructive?: boolean;
  resolve: (ok: boolean) => void;
}

export interface PasswordPromptRequest {
  title: string;
  message: string;
  /** Shown on the previous attempt's failure (wrong password), else undefined. */
  error?: string;
  resolve: (password: string | null) => void;
}

export type Zoom = number | 'fit-width' | 'fit-page';

interface UiStore {
  activeTool: ToolId;
  /** Selected page ids, in selection order. */
  selection: string[];
  /** Anchor for shift-click range selection. */
  anchorId: string | null;
  /** Page shown/centered in the main viewer. */
  currentPageId: string | null;
  /** Bumped to ask the viewer to scroll to `currentPageId`. */
  scrollRequest: number;
  zoom: Zoom;
  /** Resolved CSS-px-per-point scale, reported by the viewer. */
  effectiveScale: number;
  /** In-progress crop rectangle, in display space of the current page. */
  cropDraft: FracRect | null;
  job: Job | null;
  toasts: Toast[];
  confirm: ConfirmRequest | null;
  passwordPrompt: PasswordPromptRequest | null;

  setTool(tool: ToolId): void;
  setSelection(ids: string[], anchorId?: string | null): void;
  goToPage(id: string, scroll?: boolean): void;
  setZoom(zoom: Zoom): void;
  setEffectiveScale(scale: number): void;
  setCropDraft(rect: FracRect | null): void;
  setJob(job: Job | null): void;
  updateJob(patch: Partial<Job>): void;
  toast(kind: Toast['kind'], message: string): void;
  dismissToast(id: number): void;
  askConfirm(req: Omit<ConfirmRequest, 'resolve'>): Promise<boolean>;
  resolveConfirm(ok: boolean): void;
  askPassword(req: Omit<PasswordPromptRequest, 'resolve'>): Promise<string | null>;
  resolvePassword(password: string | null): void;
  reset(): void;
}

let toastId = 0;

const initial = {
  activeTool: 'organize' as ToolId,
  selection: [] as string[],
  anchorId: null,
  currentPageId: null,
  scrollRequest: 0,
  // Fits the whole page in view by default, like most PDF viewers — "fit width"
  // alone makes tall/portrait pages taller than the viewport, forcing an
  // immediate scroll before the user sees anything.
  zoom: 'fit-page' as Zoom,
  effectiveScale: 1,
  cropDraft: null,
  job: null,
  confirm: null,
  passwordPrompt: null,
};

export const useUiStore = create<UiStore>((set, get) => ({
  ...initial,
  toasts: [],

  setTool: (activeTool) => set({ activeTool, cropDraft: null }),
  setSelection: (selection, anchorId) => set({ selection, ...(anchorId !== undefined ? { anchorId } : {}) }),
  goToPage: (currentPageId, scroll = true) =>
    set((s) => ({ currentPageId, cropDraft: null, scrollRequest: scroll ? s.scrollRequest + 1 : s.scrollRequest })),
  setZoom: (zoom) => set({ zoom }),
  setEffectiveScale: (effectiveScale) => set({ effectiveScale }),
  setCropDraft: (cropDraft) => set({ cropDraft }),
  setJob: (job) => set({ job }),
  updateJob: (patch) => set((s) => (s.job ? { job: { ...s.job, ...patch } } : {})),

  toast: (kind, message) => {
    const id = ++toastId;
    set((s) => ({ toasts: [...s.toasts.slice(-3), { id, kind, message }] }));
    if (kind !== 'error') setTimeout(() => get().dismissToast(id), 4000);
  },
  dismissToast: (id) => set((s) => ({ toasts: s.toasts.filter((t) => t.id !== id) })),

  askConfirm: (req) =>
    new Promise<boolean>((resolve) => {
      get().confirm?.resolve(false);
      set({ confirm: { ...req, resolve } });
    }),
  resolveConfirm: (ok) => {
    const req = get().confirm;
    set({ confirm: null });
    req?.resolve(ok);
  },

  askPassword: (req) =>
    new Promise<string | null>((resolve) => {
      get().passwordPrompt?.resolve(null);
      set({ passwordPrompt: { ...req, resolve } });
    }),
  resolvePassword: (password) => {
    const req = get().passwordPrompt;
    set({ passwordPrompt: null });
    req?.resolve(password);
  },

  reset: () => set({ ...initial }),
}));
