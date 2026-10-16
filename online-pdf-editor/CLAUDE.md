# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

This repository currently contains **no source code** — only planning documents:
- `PDF-Editor-PRD.md` — the full product requirements document (features, feasibility, architecture, UX, risks).
- `AI-Build-Prompt.md` — the phased build prompt derived from the PRD, meant to be handed to a coding assistant.

There is no `package.json`, build tooling, or app scaffold yet. Before writing any code, read both files in full — they are the source of truth for scope, tech choices, and sequencing. Do not re-derive requirements from scratch; the PRD has already made the feasibility tradeoffs explicit (✅ fully feasible, ⚠️ feasible with caveats, 🔶 approximate only).

## What this project is

"ClientPDF" is a **fully client-side, browser-based PDF editor** (Sejda-style: merge, split, edit, annotate, sign, secure, OCR, convert, compress). The single non-negotiable constraint: **no file content may ever leave the browser** — no upload to any server, ever. The app must build to static files (HTML/CSS/JS) deployable to any static host or shared hosting via FTP.

## Required tech stack

Per `AI-Build-Prompt.md`, use exactly this stack unless there's a strong justification to deviate — and ask first if deviating:
- React + Vite (TypeScript)
- `pdf.js` — render pages to canvas, text extraction
- `pdf-lib` — create/modify PDFs (merge, split, rotate, forms, images, text, watermark)
- Fabric.js or Konva.js (pick one, justify the choice) — annotation/drawing overlay layer
- Tesseract.js — OCR, run in a Web Worker
- jsdiff — text-based PDF comparison
- `docx` (npm package) — best-effort PDF→Word export
- Zustand — state management, with a command-pattern undo/redo stack
- IndexedDB (via idb-keyval or similar) — autosave/draft recovery
- Web Workers — OCR, compression, batch operations (never block the main thread)
- Tailwind CSS — styling

PDF encryption (password protect/remove, permissions) is **not** covered by pdf-lib and needs a separate WASM solution (e.g. a qpdf-wasm port) — treat this and PDF→DOCX fidelity as the two highest-risk areas per the PRD's feasibility table (§5.6, §5.8).

## Intended architecture

Everything happens in one browser tab, no backend for core functionality:

```
File input (drag/drop) → pdf.js (render/extract) → Canvas viewer + annotation overlay
                                                            │
                                                     Editor state (Zustand, undo/redo)
                                                            │
                                    Web Workers (OCR / compress / batch) ←→ editor state
                                                            │
                                                        pdf-lib (write output)
                                                            │
                                              Download, or IndexedDB autosave
```

Planned folder structure (adjust only with good reason — see `AI-Build-Prompt.md` for full rationale per folder):
```
src/
  components/
    Viewer/       pdf.js canvas rendering, zoom/pan, thumbnails
    Toolbar/      per-tool contextual toolbars
    Annotate/     Fabric.js/Konva.js overlay components
    ToolCards/    landing page grid
  tools/
    organize/     merge, split, rotate, reorder, extract, insert, crop, resize
    edit/         text, shapes, draw, highlight, whiteout, redact, links, notes
    design/       watermark, page numbers, header/footer, background, grayscale
    forms/        fill, create, flatten
    sign/         draw/type/upload signature, placement
    security/     protect, unlock, permissions
    ocr/          Tesseract integration
    convert/      pdf<->image, pdf->text/docx
    optimize/     compress, repair, compare, extract-images
    batch/        multi-file operations
  workers/        Web Worker entrypoints for OCR/compress/batch
  store/          Zustand stores: document state, undo/redo history, UI state
  lib/            thin wrappers around pdf-lib/pdf.js/Fabric for reuse across tools
  App.tsx
```

An optional lightweight PHP+MySQL layer may be added *later* purely for user accounts/saved presets — it must never handle file content, and is out of scope until Phase 4.

## Build sequencing

The PRD/build prompt specify five phases, each meant to be completed, manually verified, and reported on before moving to the next — do not jump ahead or build multiple phases speculatively in one pass:
1. **Foundation + core page operations** — scaffold, upload/landing page, PDF viewer (pdf.js), page ops via pdf-lib (merge/split/delete/reorder/extract/insert/rotate/crop/resize), undo/redo.
2. **Visual editing tools** — annotation overlay (text/shapes/draw/highlight/whiteout/links/notes), flatten-to-PDF on export, watermark/page numbers/header-footer/background, redaction (explicit "visual cover" vs "true redact" modes, must be labeled distinctly in the UI).
3. **Forms, signatures, security** — AcroForm fill/flatten, simple form field designer, signature capture (draw/type/upload) and placement, password protect/remove + permissions via a WASM encryption solution.
4. **OCR, conversion, comparison, optimization** — Tesseract.js OCR with invisible re-embedded text layer, PDF↔image, PDF→text/DOCX (labeled best-effort), text+visual PDF compare, compress, batch processing via Web Workers.
5. **Polish, PWA, deployment** — offline service worker (cache app shell, not user files), accessibility (keyboard nav, ARIA, WCAG AA), responsive layout (full toolset desktop, simplified mobile/tablet), persistent privacy banner, verified zero-server-dependency static build, DEPLOY.md for shared-hosting FTP/cPanel upload.

## Quality bar (non-negotiable per the build prompt)

- Every tool must handle a corrupt/unsupported file with a clear error message, never a silent crash.
- Every destructive action (delete page, redact, remove password) requires an explicit confirm step.
- No feature may freeze the UI thread — heavy work belongs in Web Workers.
- Write unit tests for the pdf-lib/pdf.js wrapper functions in `src/lib/` — these are the highest-risk correctness areas.
- Do not fabricate support for a feature that doesn't actually work end-to-end. If a PRD feature turns out to be infeasible client-side during implementation, stop and report the specific blocker rather than shipping a stub — this applies especially to encryption and PDF→DOCX, which the PRD already flags as uncertain (⚠️/🔶).
- Redaction specifically needs two distinct, clearly labeled modes: "Visual cover" (opaque box, fast, reversible) and "True redact" (rasterizes the affected page region to actually remove underlying text) — never let the fast mode be mistaken for real redaction.
