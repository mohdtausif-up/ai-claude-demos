# ClientPDF

A fully client-side, browser-based PDF editor. No file content ever leaves the browser — see `PDF-Editor-PRD.md` and `AI-Build-Prompt.md` for the full spec and phased build plan, and `CLAUDE.md` for engineering guidance on this repo.

## Status: Phase 2 complete; Phase 3 in progress (Security, Signatures and form filling done; a form field *designer* is not built)

### What works

**Phase 1 — Foundation:**
- **Landing page** — drag-and-drop or click-to-browse upload, tool cards grouped by category (unavailable Phase 3+ tools are visibly disabled, not faked).
- **Viewer** — pdf.js-rendered page canvas, zoom (fit-page / fit-width / fixed %), page navigation, thumbnail sidebar with drag-to-reorder, multi-select (click/shift/ctrl), and right-click delete/rotate/extract.
- **Page operations (pdf-lib)** — merge, split (by range or every N pages, ZIP for multiple outputs), delete, reorder, extract, insert (blank page or from another file), rotate, crop (interactive drag handles), resize (paper presets + custom mm, content fit-and-centered).
- **Undo/redo** — command-pattern history (`src/store/history.ts`), wired to Ctrl+Z / Ctrl+Y and the toolbar.
- **Background processing** — all pdf-lib work runs in a Web Worker (`src/workers/pdfOps.worker.ts`) with progress + cancel.

**Phase 2 — Visual Editing Tools:**
- **Annotation overlay** (`src/components/Annotate/`, Fabric.js) — text boxes (font/size/color/align), shapes (rectangle/ellipse), lines/arrows, freehand draw, whiteout, hyperlinks, sticky notes. Fabric is used purely as the interactive editing surface; the persisted model (`src/lib/annotations.ts`) and the export path are framework-agnostic.
- **Flatten to PDF** (`src/lib/flattenAnnotations.ts`) — text/shapes/draw/whiteout/highlight are baked into the actual page content stream on export (not left as a separate overlay); links and sticky notes are added as real, independent PDF annotation objects (`src/lib/pdfAnnotObjects.ts`) so links stay clickable.
- **Highlight / underline / strikethrough** (`src/components/Annotate/TextLayerHighlighter.tsx`) — a real pdf.js text layer lets you drag-select actual text; the selection becomes one markup annotation per line-fragment.
- **Redaction, two explicit modes** (per the PRD's quality bar — never conflated in the UI): **Visual cover** (opaque box, fast, reversible) and **True redact** (`src/lib/trueRedact.ts`) — rasterizes the affected page and discards the underlying vector/text content, so the hidden text is actually gone, not just covered.
- Annotation edits go through the same undo/redo history as page operations (`src/tools/edit/commands.ts`).
- **Design settings** (`src/lib/design.ts`, `src/lib/drawDesign.ts`, the toolbar's "Design…" dialog) — document-wide watermark (text, size, color, opacity, rotation), page numbers (position, format with `{page}`/`{total}`/`{filename}`/`{date}` placeholders, starting number), header/footer (independent left/center/right text in both), and a solid background color — each optionally scoped to all pages or a custom page range, applied at export time. Background is genuinely layered *behind* the page's existing content (the original content is embedded as a Form XObject on a fresh page with the fill drawn first), not just a box drawn on top — the one disclosed trade-off is that any pre-existing PDF-native annotations on the source page (not this app's own links/notes, which are added independently either way) are dropped on pages where background is applied.

**Phase 3 — Forms, Signatures, Security (form field designer not yet built):**
- **Protect PDF** (`src/lib/qpdfClient.ts`, the toolbar's "Protect…" dialog) — sets a password and permissions (printing, modifying, copying) on a downloaded copy, AES-256, via a vendored WASM build of [qpdf](https://qpdf.sourceforge.net/). pdf-lib has no encryption support at all, so this is the one place the required tech stack was necessarily extended — see `public/qpdf/PROVENANCE.md` for exactly where the binary comes from and the trust trade-off involved (it's a third-party build, not the official qpdf project; disclosed rather than silently trusted).
- **Remove password** — opening a password-protected file prompts for its password (trying an empty password first, silently, for permissions-only PDFs that have no open password) and decrypts it via the same WASM build; from then on it's an ordinary open document, so a normal Download produces an unprotected copy. There's no separate "unlock" UI — opening the file *is* the unlock step.
- **Signatures** (`SignatureAnnotation` in `src/lib/annotations.ts`, the Annotate toolbar's "Signature" tool) — draw freehand, type (rendered via an offscreen canvas in a cursive font), or upload a PNG/JPEG, then place/move/resize it like any other annotation. This is explicitly a **visual** signature image baked into the page — not a cryptographic digital signature (no certificate, PKI or tamper-evidence), and the UI says so.
- **Fill existing forms** (`src/lib/formFields.ts`, the toolbar's "Fill form" mode) — detects a source PDF's existing AcroForm fields (text, checkbox, radio, dropdown, listbox) at import time and renders real HTML controls over each field's actual position so you can fill them in; values are applied onto the real AcroForm at export, with an optional "Flatten on export" toggle to bake them in and remove interactivity. A form untouched by this app's fill UI still keeps its fields fillable in the output — pdf-lib's page-copying has no notion of the catalog-level `/AcroForm`, so this required manually re-registering the copied widgets into the output document's form (see the comment on `reconcileCopiedFormFields`).
- **Not built:** a form field *designer* (adding brand-new fields to a PDF that doesn't already have them) — explicitly out of scope for this pass.

### Explicitly not yet built
A form field designer (see above), OCR, format conversion, compression/compare, batch processing, PWA/offline, and full accessibility/responsive polish. The landing page shows these as disabled cards so nothing is misrepresented as working.

### Known scope limits (Phase 2, disclosed rather than hidden)
- Text/shape/whiteout/link objects can be moved and resized but not freely rotated; lines, arrows, freehand strokes, sticky notes and markup can be moved but not reshaped after placement (delete and redraw to change their shape) — deliberate v1 scope choices, not bugs.
- Only the 14 standard PDF fonts (Helvetica/Times/Courier, regular/bold/italic) are offered for text — no custom font upload.
- The freehand pen tool and arrow annotations always render at full opacity; the opacity slider applies to shapes, text, whiteout and highlight, which go through pdf-lib's high-level drawing API.
- Highlight/underline/strikethrough require the page's full, uncropped view (pdf.js's text layer has no notion of this app's custom crop) — the viewer temporarily shows the full page while one of those tools is active.

## Verification

- **Unit tests** (`npm test`) — 132 tests covering the pdf-lib/pdf.js wrapper functions in `src/lib/` (the highest-risk correctness area per the PRD): page inspection, crop/rotate/resize math, annotation-to-PDF flattening (verified against the actual drawing operators pdf-lib emits), redaction (both modes), link/note/signature annotation objects, coordinate conversion under page rotation (including link/note placement when both crop and resize apply to the same page — a past regression, see below), range parsing, design-settings page selectors/placeholders, watermark/page-number/header-footer text placement, background layering (verified via the embedded-XObject structure, not just a visual check, including that a page's form fields and non-contentful/blank pages survive it), AcroForm field extraction/value-application/flattening (text/checkbox/radio/dropdown, including the radio-group export-value-vs-internal-appearance-state distinction, and that a value applies consistently when the same form-bearing page is repeated in one output), and the undo/redo command layers. qpdf-based encryption isn't covered here — it needs a real Worker/WASM runtime a unit test environment can't provide, so it's verified end-to-end instead (below).
- **Bug-fix pass (post-Phase-3 review):** a focused correctness review of `src/lib/` surfaced and fixed four real, previously-untested bugs, all now covered by regression tests: (1) a link/note annotation landed at the wrong position/size on any page with *both* a crop and a resize applied, because the annotation-placement math refit the page's full original box into the resize target instead of the already-cropped box pdf-lib itself resizes against; (2) applying a background to a page re-embeds its content as a Form XObject (the only way to draw genuinely *behind* existing content in pdf-lib), which silently dropped that page's AcroForm widget annotations — any fillable field on a page with a background vanished from the output entirely, together with any value the user had typed; (3) for the same reason, a completely blank page carrying only form fields (no drawn content, so no `/Contents` stream) crashed the whole export when a background was applied, since pdf-lib's page-embedding requires a content stream to exist; (4) when a page list intentionally repeats the same source page more than once (e.g. extracting "1, 1"), each repeat gets its own independent copy of that page's form fields, but a value typed into the fill UI was only being applied to the first copy, leaving later copies with their original/blank value.
- **E2E tests** (`npm run test:e2e`, Playwright against Microsoft Edge) — 20 tests driving the real built app, including: rotate/crop/split/merge/undo-redo from Phase 1; from Phase 2's annotation tools — drawing a rectangle and a text box via the interactive canvas and verifying they land in the exported PDF, undo removing a freshly-drawn annotation, adding a link that becomes a real PDF annotation object, deleting a selected annotation with the Delete key, and true redaction verified by confirming the redacted page's exported content has **no text-drawing operators left at all** (not just a box drawn on top); from Phase 2's design settings — adding a watermark and page numbers through the real dialog and confirming both survive export, and applying a background color plus header/footer text and confirming the exported page's content was genuinely restructured (original content moved into an embedded XObject) rather than just painted over; and from Phase 3 — drawing a signature on the real canvas pad and confirming it survives export as an embedded image, filling an existing form field through the real overlay and confirming the value lands in the AcroForm (and that flattening removes the field afterward), and Security's protect/unlock round trip (protect → re-open the protected file, which prompts for a password and rejects a wrong one with a visible error → unlock with the right password → download an unprotected copy) against the real qpdf WASM build, not a mock.
- **Production build** (`npm run build`) — static `dist/` bundle with zero server dependencies.

## Development

```sh
npm install
npm run dev        # http://localhost:5173
npm run build      # static build in dist/
npm run preview    # serve the production build locally
npm test           # unit tests (vitest)
npm run test:e2e   # e2e tests (playwright, requires Edge or Chromium installed)
npm run typecheck
```

pdf.js's runtime assets (CMaps, standard fonts, wasm decoders) are copied into `public/pdfjs/` by `scripts/copy-pdfjs-assets.mjs`, which runs automatically before `dev` and `build`.

E2E tests are heavier than the unit suite (Fabric.js + pdf.js in a real browser); `playwright.config.ts` caps parallelism at 2 workers by default since higher parallelism caused download events to stall on a resource-constrained machine — raise it with `--workers` if your machine has room to spare.
