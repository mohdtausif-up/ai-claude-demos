# Product Requirements Document: ClientPDF — Browser-Based PDF Editor

## 1. Overview

ClientPDF is a fully client-side, browser-based PDF editing web application inspired by Sejda.com. All PDF processing happens locally in the user's browser using JavaScript/WebAssembly — no files are ever uploaded to a server. This makes it deployable as static files on any hosting (including shared hosting), maximizes user privacy, and eliminates server compute/storage costs.

**Vision:** A free, private, feature-rich alternative to Sejda/iLovePDF/SmallPDF that never sends a user's document off their device.

## 2. Goals

- Replicate the core Sejda feature set for editing, organizing, converting, securing, and signing PDFs.
- 100% client-side processing — no file leaves the browser (verifiable, privacy-first selling point).
- Works entirely as static files — deployable to any static host or shared hosting.
- Responsive UI usable on desktop and tablet; graceful degradation on mobile.
- Handle reasonably large files (up to ~100–200MB) without crashing the browser tab.

## 3. Non-Goals (v1)

- Perfect-fidelity PDF-to-Word/Excel/PowerPoint conversion (layout fidelity will be approximate — see §7.5).
- Real-time multi-user collaboration.
- Cloud storage/account sync (may be added later as an optional PHP+MySQL layer, not required for core editing).
- Legally-binding certificate-based digital signatures (only visual/drawn signature placement in v1).

## 4. Target Users

- Individuals/small businesses needing quick PDF edits without installing software or paying for SaaS.
- Privacy-conscious users (legal, healthcare, HR) who cannot upload documents to third-party servers.
- Developers/agencies wanting a self-hostable, white-label PDF tool.

## 5. Feature Requirements

Features are grouped by category and tagged with client-side feasibility:
- ✅ **Fully feasible** — solid client-side libraries exist, good fidelity.
- ⚠️ **Feasible with caveats** — possible client-side but heavier (WASM) or lower fidelity than server-based tools.
- 🔶 **Approximate only** — client-side version will not match server-grade quality; document this clearly in UI.

### 5.1 Core Page Operations
| Feature | Feasibility | Notes |
|---|---|---|
| Merge multiple PDFs | ✅ | `pdf-lib` |
| Split PDF (by page ranges / every N pages / by bookmark) | ✅ | `pdf-lib` |
| Delete pages | ✅ | `pdf-lib` |
| Reorder pages (drag & drop) | ✅ | `pdf-lib` + thumbnail UI |
| Extract pages (save subset as new PDF) | ✅ | `pdf-lib` |
| Insert pages (from another PDF or blank page) | ✅ | `pdf-lib` |
| Rotate pages (90/180/270, per-page or all) | ✅ | `pdf-lib` |
| Crop pages | ✅ | `pdf-lib` mediabox manipulation |
| Resize / rescale pages | ✅ | `pdf-lib` |

### 5.2 Visual Editing Tools
| Feature | Feasibility | Notes |
|---|---|---|
| Add/edit text boxes (font, size, color, alignment) | ✅ | Canvas overlay (Fabric.js/Konva.js) → flattened into PDF via `pdf-lib` |
| Add images (PNG/JPG) with resize/rotate/move | ✅ | `pdf-lib` image embedding |
| Add shapes (rectangle, ellipse, line, arrow) | ✅ | Fabric.js/Konva.js drawing tools |
| Freehand drawing/annotation | ✅ | Canvas pointer events |
| Highlight / underline / strikethrough text | ✅ | PDF.js text layer + overlay annotations |
| Whiteout (cover content with white box) | ✅ | Draw + flatten opaque rectangle |
| Redact (permanently remove underlying text/content, not just cover) | ⚠️ | Requires re-flattening page to image or content-stream editing — flag as "true redaction" vs "visual cover" mode |
| Add hyperlinks | ✅ | `pdf-lib` link annotations |
| Add sticky notes / comments | ✅ | PDF annotation objects |
| Undo/redo across all edits | ✅ | Command-pattern state history |

### 5.3 Page Design & Branding
| Feature | Feasibility | Notes |
|---|---|---|
| Add watermark (text or image, opacity, rotation, tiling) | ✅ | `pdf-lib` |
| Add page numbers (position, format, start number) | ✅ | `pdf-lib` |
| Add header/footer (text, date, page count) | ✅ | `pdf-lib` |
| Grayscale conversion | ⚠️ | Requires rasterizing pages to canvas then re-embedding as images (loses vector/text crispness and increases file size) |
| Add background color/image | ✅ | `pdf-lib` |

### 5.4 Forms
| Feature | Feasibility | Notes |
|---|---|---|
| Fill existing AcroForm fields | ✅ | `pdf-lib` form API |
| Flatten filled forms | ✅ | `pdf-lib` |
| Create simple form fields (text field, checkbox, radio, dropdown) | ✅ | `pdf-lib` form creation API |
| Detect form fields visually for filling UI | ✅ | `pdf-lib` field introspection |

### 5.5 Signatures
| Feature | Feasibility | Notes |
|---|---|---|
| Draw signature (mouse/touch) | ✅ | Canvas signature pad |
| Type signature (styled fonts) | ✅ | Canvas text render |
| Upload signature image (transparent PNG) | ✅ | `pdf-lib` image embed |
| Place/resize/reposition signature on any page | ✅ | Overlay + `pdf-lib` |
| Multiple signers / signature fields (workflow) | 🔶 | v2 — requires state tracking per signer, no server to coordinate multi-party signing |
| Certificate-based (PKI) digital signature | 🔶 | Out of scope v1 — requires private key handling; consider WebCrypto + X.509 in v2 if needed |

### 5.6 Security
| Feature | Feasibility | Notes |
|---|---|---|
| Add password protection (encrypt PDF) | ⚠️ | `pdf-lib` does not natively support encryption; requires a WASM port (e.g., qpdf-wasm) or a JS AES/RC4 PDF-encryption implementation |
| Remove password (when password is known/provided by user) | ⚠️ | Same tooling as above |
| Set permissions (disallow printing/copying/editing) | ⚠️ | Same tooling as above |

### 5.7 OCR
| Feature | Feasibility | Notes |
|---|---|---|
| OCR scanned PDF to make text searchable/selectable | ✅ | **Tesseract.js** (WASM Tesseract) — render each page to canvas, OCR, re-embed as invisible text layer via `pdf-lib` |
| Multi-language OCR | ✅ | Tesseract.js supports 100+ language packs (loaded on demand) |

### 5.8 Conversion
| Feature | Feasibility | Notes |
|---|---|---|
| PDF → JPG/PNG (per page) | ✅ | PDF.js render to canvas → export |
| JPG/PNG → PDF | ✅ | `pdf-lib` image embedding |
| PDF → plain text | ✅ | PDF.js text extraction |
| PDF → Word (.docx) | 🔶 | Extract text/basic layout via PDF.js, generate `.docx` with `docx` JS library — approximate layout only, not pixel-perfect |
| PDF → Excel (.xlsx) | 🔶 | Only feasible for table-like content; heuristic table detection is unreliable — mark as "best effort" |
| Word/Excel → PDF | 🔶 | Very hard client-side without a rendering engine; realistically out of scope for v1 (recommend: "open in Word/Sheets and print to PDF" as the honest fallback) |
| PDF → PDF/A (archival format) | 🔶 | Requires embedding fonts + color profiles per ISO spec; partial support in v2 |

### 5.9 Optimization & Maintenance
| Feature | Feasibility | Notes |
|---|---|---|
| Compress PDF (reduce file size) | ⚠️ | Recompress embedded images via canvas re-encode (JPEG quality reduction) using `pdf-lib`; won't match Ghostscript-grade compression |
| Repair corrupted PDF | 🔶 | Limited — `pdf-lib`'s parser can sometimes recover/re-save malformed PDFs; not guaranteed |
| Compare two PDFs (text + visual diff) | ✅ | Text diff via `jsdiff` on PDF.js-extracted text; visual diff via canvas pixel comparison overlay |
| Extract all images from PDF | ✅ | `pdf-lib`/PDF.js object introspection |

### 5.10 Batch / Workflow
| Feature | Feasibility | Notes |
|---|---|---|
| Batch process multiple files with same operation (e.g., watermark 10 files) | ✅ | Loop client-side using Web Workers to keep UI responsive |
| Save/reuse tool presets (e.g., "always watermark like this") | ✅ | `localStorage`/IndexedDB |

## 6. Non-Functional Requirements

- **Privacy:** No network calls containing file content. Explicit UI messaging: "Your files never leave your device."
- **Performance:** Heavy operations (OCR, compression, large merges) must run in **Web Workers** to avoid blocking the UI thread. Show progress bars for anything >1 second.
- **File size handling:** Warn users above a configurable threshold (e.g., 150MB) about possible browser memory limits; process page-by-page/streaming where possible rather than loading entire file into memory at once.
- **Browser support:** Latest 2 versions of Chrome, Firefox, Edge, Safari. Graceful "unsupported browser" message otherwise.
- **Offline capability:** Ship as a Progressive Web App (PWA) with service worker caching so it works after first load without internet.
- **Accessibility:** Keyboard navigation for all tools, ARIA labels, sufficient color contrast (WCAG AA).
- **Responsive design:** Full functionality on desktop; simplified/limited toolset on mobile/tablet (viewing, signing, basic annotation).
- **No installation required:** Pure web app, works from a URL.

## 7. Technical Architecture

### 7.1 Stack
- **Framework:** React (or Vue) SPA, bundled with Vite.
- **PDF rendering:** `pdf.js` (Mozilla) for viewing/rasterizing pages and text extraction.
- **PDF creation/manipulation:** `pdf-lib` for merge/split/rotate/forms/watermark/images/text embedding.
- **Canvas annotation layer:** `Fabric.js` or `Konva.js` for drawing, shapes, text boxes, signatures — rendered on top of PDF.js canvas, then flattened into the PDF via `pdf-lib`.
- **OCR:** `Tesseract.js`.
- **Diffing:** `jsdiff` for text comparison.
- **Word export:** `docx` (JS library) for best-effort DOCX generation.
- **Encryption:** WASM port of `qpdf` (or equivalent JS PDF-crypto implementation) for password protect/remove.
- **State management:** Zustand or Redux Toolkit for editor state, undo/redo history (command pattern).
- **Persistence:** IndexedDB for autosave/draft recovery (survive accidental tab close) — cleared after export or on user request.
- **Heavy processing:** Web Workers for OCR, compression, batch operations to keep main thread responsive.
- **Packaging:** Static build output (HTML/CSS/JS bundle) — deployable to any static host, CDN, or shared hosting via FTP.

### 7.2 High-Level Architecture

```
┌─────────────────────────────────────────────────────┐
│                     Browser Tab                       │
│                                                         │
│  ┌───────────┐   ┌──────────────┐   ┌──────────────┐  │
│  │  File      │──▶│  PDF.js       │──▶│  Canvas       │  │
│  │  Input     │   │  (render/     │   │  Viewer +     │  │
│  │ (drag/drop)│   │   extract)    │   │  Annotation   │  │
│  └───────────┘   └──────────────┘   │  Overlay      │  │
│                                        └──────┬───────┘  │
│                                               │           │
│                                        ┌──────▼───────┐  │
│                                        │  Editor State │  │
│                                        │  (undo/redo)  │  │
│                                        └──────┬───────┘  │
│                                               │           │
│              ┌────────────────────────────────┼────────┐ │
│              │           Web Workers           │        │ │
│              │  (OCR / Compress / Batch ops)   │        │ │
│              └────────────────────────────────┬┘        │ │
│                                               │           │
│                                        ┌──────▼───────┐  │
│                                        │   pdf-lib     │  │
│                                        │ (write output)│  │
│                                        └──────┬───────┘  │
│                                               │           │
│                                        ┌──────▼───────┐  │
│                                        │  Download /   │  │
│                                        │  IndexedDB    │  │
│                                        │  autosave     │  │
│                                        └──────────────┘  │
└─────────────────────────────────────────────────────────┘
```

No backend component is required for core functionality. An optional lightweight PHP+MySQL layer can be added later purely for user accounts/saved presets — never for file processing.

## 8. UI/UX Requirements

- **Landing page:** Grid of tool cards (like Sejda's homepage) grouped by category (Organize, Edit, Convert, Sign & Fill, Security, Optimize).
- **Editor layout (once a file is loaded):**
  - Left sidebar: page thumbnails (drag to reorder, right-click for delete/rotate/extract).
  - Center: main canvas viewer with zoom/pan controls.
  - Top toolbar: tool-specific actions (contextual based on selected tool).
  - Right panel: properties of selected object (font, color, size, opacity, etc.).
  - Bottom/floating: Undo/redo, page navigation, export button.
- **Drag-and-drop file upload** as the primary entry point on every tool page.
- **Progress indicators** for any operation taking >1s, with a cancel option.
- **Export flow:** Preview before download; option to download or (if account layer exists later) save to library.
- **Clear privacy messaging** throughout: badge/banner stating "Processed 100% in your browser — never uploaded."

## 9. Success Metrics

- Time-to-first-edit (file load → first successful tool action) under 5 seconds for files <20MB.
- Zero file-content network requests (verifiable via browser dev tools — a trust/marketing feature).
- Support for files up to 150MB without tab crash on a mid-range laptop (8GB RAM).
- Feature parity checklist: ≥80% of Sejda's top 20 most-used tools available at v1 GA.

## 10. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Large PDFs exhaust browser memory | Stream/process page-by-page where the library allows; warn users above size threshold |
| OCR/compression slow on low-end devices | Run in Web Worker; show progress + allow cancel; consider quality/speed toggle |
| PDF encryption libraries in JS/WASM are less mature than server tools (qpdf, Ghostscript) | Clearly scope supported encryption standards (e.g., AES-128/256 PDF standard security handler); test against real-world encrypted PDFs early |
| True Office format conversion fidelity expectations mismatch vs Sejda | Set explicit "best effort" messaging in UI for these specific tools; don't oversell |
| Browser compatibility gaps (Safari WASM quirks, older browsers) | Feature-detect and gracefully disable unsupported tools with a clear message |

## 11. Phased Roadmap

**Phase 1 — MVP (Organize + Basic Edit)**
Merge, split, delete/reorder/rotate/extract pages, add text/image/shapes, watermark, page numbers, PDF↔JPG/PNG, basic signature (draw/type/upload + place).

**Phase 2 — Forms, Security, OCR**
Fill/create forms, password protect/remove, OCR (searchable PDF), redact, compare PDFs.

**Phase 3 — Conversion & Optimization**
PDF→Word/Excel (best-effort), compress, grayscale, PDF/A, repair, batch processing, presets, PWA/offline support.

**Phase 4 — Optional Account Layer**
Lightweight PHP+MySQL backend purely for saved presets/history/branding — no file content ever touches it.

## 12. Known Limitations vs. Sejda (Be Transparent With Users)

- Word/Excel conversion will be lower-fidelity (layout approximation, not pixel-perfect).
- No cloud storage integrations (Dropbox/Google Drive import) in v1 — local file only.
- No enterprise features (audit trails, team workspaces, API access) in v1.
- Encryption/decryption relies on maturing WASM libraries — coverage of every PDF encryption variant is not guaranteed at launch.
