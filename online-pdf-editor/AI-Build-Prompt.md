## AI Prompt — Build "ClientPDF" (Browser-Based PDF Editor)

Copy/paste the prompt below into Claude Code, Cursor, or another AI coding assistant. It references the full PRD (`PDF-Editor-PRD.md`) — attach/paste that file alongside this prompt if your tool supports file context.

---

```
You are building "ClientPDF" — a fully client-side, browser-based PDF editing web app,
inspired by Sejda.com, following the attached PRD (PDF-Editor-PRD.md). Read the PRD fully
before writing any code.

CORE CONSTRAINT: Every feature must run entirely in the browser (JS/WASM). No file content
may ever be sent to a server. The app must be deployable as static files (HTML/CSS/JS) to
any static host or shared hosting.

TECH STACK (use exactly these unless you have a strong justification to deviate — ask first):
- React + Vite (TypeScript)
- pdf.js for rendering pages to canvas and text extraction
- pdf-lib for creating/modifying PDFs (merge, split, rotate, forms, images, text, watermark)
- Fabric.js (or Konva.js — pick one and justify) for the annotation/drawing overlay layer
- Tesseract.js for OCR
- jsdiff for PDF comparison (text diff)
- docx (npm package) for best-effort PDF→Word export
- Zustand for state management, with a command-pattern undo/redo stack
- IndexedDB (via idb-keyval or similar) for autosave/draft recovery
- Web Workers for OCR, compression, and batch operations — never block the main thread
- Tailwind CSS for styling

BUILD IN PHASES — complete and verify each phase before moving to the next. After each
phase, run the app, exercise the new features manually (or via Playwright if configured),
and report what works and what doesn't before proceeding.

PHASE 1 — Foundation + Core Page Operations
1. Scaffold the Vite+React+TS project with the folder structure below.
2. Build the file upload/drag-and-drop landing page with tool cards grouped by category
   (Organize, Edit, Convert, Sign & Fill, Security, Optimize) — see PRD §8.
3. Build the core PDF viewer: load a PDF via pdf.js, render pages to canvas, page
   thumbnail sidebar (drag to reorder), zoom/pan controls, page navigation.
4. Implement page operations via pdf-lib: merge multiple PDFs, split (by range / every N
   pages), delete pages, reorder pages, extract pages as new PDF, insert blank/external
   pages, rotate (per-page and all), crop, resize.
5. Implement undo/redo via a command-pattern history store (Zustand).
6. Every operation must show a progress indicator for anything >1s and be cancelable.

PHASE 2 — Visual Editing Tools
1. Build the annotation overlay layer on top of the PDF.js canvas using Fabric.js/Konva.js:
   text boxes (font/size/color/alignment), shapes (rect/ellipse/line/arrow), freehand draw,
   highlight/underline/strikethrough on selectable text, whiteout, hyperlink annotations,
   sticky notes.
2. Implement "flatten to PDF" — merge overlay annotations into the actual PDF content via
   pdf-lib on export.
3. Implement watermark (text/image, opacity, rotation, tiling), page numbers (position/
   format/start), header/footer, background color/image.
4. Implement redaction with two explicit modes in the UI: "Visual cover" (opaque box, fast)
   vs "True redact" (rasterize the affected page region to remove underlying text) — label
   the difference clearly to the user per PRD §5.2.

PHASE 3 — Forms, Signatures, Security
1. Detect and render existing AcroForm fields for filling; support flattening filled forms.
2. Build a simple form designer: add text field / checkbox / radio / dropdown to any page.
3. Build signature capture: draw (canvas pad), type (styled font rendering), upload
   (transparent PNG) — then place/resize/reposition on any page, embed via pdf-lib.
4. Implement password protect/remove and permission flags. Research and integrate a
   WASM PDF-encryption solution (e.g., a qpdf-wasm port) since pdf-lib alone doesn't cover
   this — flag any encryption variants you cannot support and surface that limitation in
   the UI rather than failing silently.

PHASE 4 — OCR, Conversion, Comparison, Optimization
1. Integrate Tesseract.js for OCR: render each page to canvas, run OCR in a Web Worker,
   re-embed an invisible searchable text layer via pdf-lib. Support language pack selection.
2. Implement PDF→JPG/PNG (per page) and JPG/PNG→PDF.
3. Implement PDF→plain text and best-effort PDF→DOCX (via the docx library) — clearly
   label this as "best effort, layout may not be preserved" in the UI per PRD §5.8.
4. Implement PDF compare: text diff (jsdiff) plus a visual side-by-side/overlay diff using
   canvas pixel comparison.
5. Implement compress (recompress embedded images via canvas re-encode with a
   quality slider) and extract-all-images.
6. Implement batch processing: apply one operation (e.g., watermark) across multiple
   uploaded files, processed via Web Workers with a per-file progress list.

PHASE 5 — Polish, PWA, Deployment
1. Add a service worker for offline capability (PWA) — cache app shell, not user files.
2. Add accessibility: keyboard nav for every tool, ARIA labels, WCAG AA contrast check.
3. Add responsive layout: full toolset on desktop, simplified toolset (view/sign/basic
   annotate) on mobile/tablet.
4. Add a persistent, unmissable privacy banner: "Processed 100% in your browser — files
   are never uploaded."
5. Verify the entire app builds to a static bundle (`vite build`) with zero server
   dependencies, and produce a short DEPLOY.md explaining how to upload the `dist/`
   folder to shared hosting via FTP/cPanel.

FOLDER STRUCTURE (adjust only if you have good reason):
src/
  components/
    Viewer/          (PDF.js canvas rendering, zoom/pan, thumbnails)
    Toolbar/          (per-tool contextual toolbars)
    Annotate/         (Fabric.js/Konva.js overlay components)
    ToolCards/        (landing page grid)
  tools/
    organize/         (merge, split, rotate, reorder, extract, insert, crop, resize)
    edit/             (text, shapes, draw, highlight, whiteout, redact, links, notes)
    design/           (watermark, page numbers, header/footer, background, grayscale)
    forms/            (fill, create, flatten)
    sign/             (draw/type/upload signature, placement)
    security/         (protect, unlock, permissions)
    ocr/              (Tesseract integration)
    convert/          (pdf<->image, pdf->text/docx)
    optimize/         (compress, repair, compare, extract-images)
    batch/            (multi-file operations)
  workers/            (Web Worker entrypoints for OCR/compress/batch)
  store/              (Zustand stores: document state, undo/redo history, UI state)
  lib/                (thin wrappers around pdf-lib/pdf.js/Fabric for reuse across tools)
  App.tsx

QUALITY BAR:
- Every tool must handle a corrupt/unsupported file gracefully with a clear error message,
  not a silent crash.
- Every destructive action (delete page, redact, remove password) needs a confirm step.
- No feature should freeze the UI thread — profile and move heavy work to Web Workers.
- Write unit tests for the pdf-lib/pdf.js wrapper functions in src/lib/ (these are the
  highest-risk correctness areas).
- Do not fabricate support for a feature that doesn't actually work end-to-end — if a PRD
  feature turns out to be infeasible client-side during implementation, stop and report
  back with the specific blocker rather than shipping a stub.

START by scaffolding Phase 1 only. Report back with what's working before I approve moving
to Phase 2.
```

---

### Notes on using this prompt
- Feed it the PRD file (`PDF-Editor-PRD.md`) as context — the prompt assumes the AI has read it.
- It's intentionally phased so you can review and course-correct after each stage rather than getting a giant, unreviewable dump of code.
- The "quality bar" section exists specifically to stop an AI coding tool from silently faking hard features (encryption, DOCX conversion) — those are the two riskiest areas per the PRD's feasibility table.
