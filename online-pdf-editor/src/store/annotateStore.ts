import { create } from 'zustand';
import { DEFAULT_FONT, type AnnotationType, type FontStyle, type RedactMode, type TextAlign } from '../lib/annotations';

export type AnnotateTool =
  | 'select'
  | 'text'
  | 'rectangle'
  | 'ellipse'
  | 'line'
  | 'arrow'
  | 'draw'
  | 'whiteout'
  | 'redact'
  | 'highlight'
  | 'underline'
  | 'strikethrough'
  | 'link'
  | 'note'
  | 'signature';

/** Maps a drawing tool to the annotation type it creates (markup tools share one type, distinguished by `kind`). */
export const TOOL_ANNOTATION_TYPE: Partial<Record<AnnotateTool, AnnotationType>> = {
  text: 'text',
  rectangle: 'rectangle',
  ellipse: 'ellipse',
  line: 'line',
  arrow: 'arrow',
  draw: 'draw',
  whiteout: 'whiteout',
  redact: 'redact',
  highlight: 'markup',
  underline: 'markup',
  strikethrough: 'markup',
  link: 'link',
  note: 'note',
  signature: 'signature',
};

export interface AnnotateStyle {
  fillColor: string;
  fillEnabled: boolean;
  strokeColor: string;
  strokeEnabled: boolean;
  strokeWidth: number;
  opacity: number;
  font: FontStyle;
  fontSize: number;
  textColor: string;
  align: TextAlign;
  markupColor: string;
  redactColor: string;
  redactMode: RedactMode;
  noteColor: string;
}

const DEFAULT_STYLE: AnnotateStyle = {
  fillColor: '#fde047',
  fillEnabled: true,
  strokeColor: '#ef4444',
  strokeEnabled: true,
  strokeWidth: 2,
  opacity: 1,
  font: DEFAULT_FONT,
  fontSize: 18,
  textColor: '#111827',
  align: 'left',
  markupColor: '#fde047',
  redactColor: '#000000',
  redactMode: 'visual',
  noteColor: '#fde047',
};

interface AnnotateStore {
  tool: AnnotateTool;
  style: AnnotateStyle;
  /** Currently selected annotation id on the Fabric canvas, for the property panel. */
  selectedId: string | null;
  setTool(tool: AnnotateTool): void;
  setStyle(patch: Partial<AnnotateStyle>): void;
  setSelectedId(id: string | null): void;
}

/** Resolves the fill/stroke toggles to the null-means-none values the annotation model expects. */
export function resolveFillStroke(style: AnnotateStyle): { fillColor: string | null; strokeColor: string | null } {
  return {
    fillColor: style.fillEnabled ? style.fillColor : null,
    strokeColor: style.strokeEnabled ? style.strokeColor : null,
  };
}

export const useAnnotateStore = create<AnnotateStore>((set) => ({
  tool: 'select',
  style: DEFAULT_STYLE,
  selectedId: null,
  setTool: (tool) => set({ tool, selectedId: null }),
  setStyle: (patch) => set((s) => ({ style: { ...s.style, ...patch } })),
  setSelectedId: (selectedId) => set({ selectedId }),
}));
