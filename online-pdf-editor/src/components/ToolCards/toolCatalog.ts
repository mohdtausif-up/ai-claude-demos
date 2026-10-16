/** Static catalog for the landing page grid (PRD §8). `available` reflects what's actually shipped. */
export interface ToolCardInfo {
  id: string;
  label: string;
  description: string;
  icon: string;
  available: boolean;
}

export interface ToolCategory {
  id: string;
  label: string;
  tools: ToolCardInfo[];
}

export const TOOL_CATEGORIES: ToolCategory[] = [
  {
    id: 'organize',
    label: 'Organize',
    tools: [
      { id: 'merge', label: 'Merge PDF', description: 'Combine multiple PDFs into one file.', icon: '🧩', available: true },
      { id: 'split', label: 'Split PDF', description: 'Split by page ranges or every N pages.', icon: '✂️', available: true },
      { id: 'organize', label: 'Organize pages', description: 'Reorder, rotate, delete or extract pages.', icon: '📑', available: true },
      { id: 'rotate', label: 'Rotate PDF', description: 'Rotate individual or all pages.', icon: '🔄', available: true },
      { id: 'crop', label: 'Crop PDF', description: 'Crop the visible area of your pages.', icon: '⬛', available: true },
      { id: 'resize', label: 'Resize pages', description: 'Change page size (A4, Letter, custom…).', icon: '📐', available: true },
    ],
  },
  {
    id: 'edit',
    label: 'Edit',
    tools: [
      { id: 'text', label: 'Add text & shapes', description: 'Text boxes, rectangles, ellipses, lines and arrows.', icon: '📝', available: true },
      { id: 'draw', label: 'Draw & whiteout', description: 'Freehand pen and opaque cover boxes.', icon: '🖊️', available: true },
      { id: 'markup', label: 'Highlight text', description: 'Highlight, underline or strike through selected text.', icon: '🖍️', available: true },
      {
        id: 'redact',
        label: 'Redact',
        description: 'Visual cover (fast) or true redact (permanently removes the hidden content).',
        icon: '⬛',
        available: true,
      },
      { id: 'links-notes', label: 'Links & sticky notes', description: 'Add clickable links and comment notes.', icon: '🔗', available: true },
    ],
  },
  {
    id: 'design',
    label: 'Design',
    tools: [
      { id: 'watermark', label: 'Watermark', description: 'Add tiled or centered text, with opacity and rotation.', icon: '💧', available: true },
      { id: 'page-numbers', label: 'Page numbers', description: 'Position, format and starting number.', icon: '#️⃣', available: true },
      { id: 'header-footer', label: 'Header & footer', description: 'Left/center/right text, with page and date placeholders.', icon: '📰', available: true },
      { id: 'background', label: 'Background color', description: 'Solid color fill behind the page content.', icon: '🎨', available: true },
    ],
  },
  {
    id: 'convert',
    label: 'Convert',
    tools: [
      { id: 'pdf-to-image', label: 'PDF to JPG/PNG', description: 'Coming in Phase 4.', icon: '🖼️', available: false },
      { id: 'image-to-pdf', label: 'JPG/PNG to PDF', description: 'Coming in Phase 4.', icon: '📄', available: false },
      { id: 'pdf-to-word', label: 'PDF to Word', description: 'Coming in Phase 4 — best effort.', icon: '📃', available: false },
    ],
  },
  {
    id: 'sign',
    label: 'Sign & Fill',
    tools: [
      { id: 'signature', label: 'Add signature', description: 'Draw, type or upload a signature and place it (via the Annotate tools).', icon: '✒️', available: true },
      { id: 'fill', label: 'Fill existing form', description: 'Fill text/checkbox/radio/dropdown fields already in a PDF; optionally flatten on export.', icon: '✍️', available: true },
      { id: 'forms', label: 'Create form', description: 'Coming soon — adding new fields to a PDF is not yet supported.', icon: '🗒️', available: false },
    ],
  },
  {
    id: 'security',
    label: 'Security',
    tools: [
      { id: 'protect', label: 'Protect PDF', description: 'Add a password and permissions (printing, editing, copying) via qpdf/WASM.', icon: '🔒', available: true },
      { id: 'unlock', label: 'Remove password', description: 'Open a protected PDF (prompts for the password) and download an unprotected copy.', icon: '🔓', available: true },
    ],
  },
  {
    id: 'optimize',
    label: 'Optimize',
    tools: [
      { id: 'compress', label: 'Compress PDF', description: 'Coming in Phase 4.', icon: '🗜️', available: false },
      { id: 'ocr', label: 'OCR (searchable PDF)', description: 'Coming in Phase 4.', icon: '🔍', available: false },
      { id: 'compare', label: 'Compare PDFs', description: 'Coming in Phase 4.', icon: '🔬', available: false },
    ],
  },
];
