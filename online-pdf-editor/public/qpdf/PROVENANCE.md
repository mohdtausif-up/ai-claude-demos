# Vendored qpdf WASM build — provenance and trust disclosure

`lib/qpdf.js`, `lib/qpdf.wasm` and `qpdf-worker.js` in this directory are a
prebuilt WebAssembly build of [qpdf](https://qpdf.sourceforge.net/), used for
client-side PDF password protection and permissions (pdf-lib has no
encryption support — see `CLAUDE.md`).

**This is not the official qpdf project.** It comes from a single
maintainer's personal fork/build:

- Source repository: https://github.com/j3k0/qpdf.js
- Commit the files were fetched from: `f9871acd9f8477d87c2aa9e5cfab9e7c61792469`
- Fetched: 2026-09-30
- License: Apache-2.0 (same as upstream qpdf)
- No GitHub Releases, version tags, or reproducible-build attestation exist
  for this binary — it is trusted as-is, the same way any prebuilt binary
  dependency would be, but with less provenance than a maintained package
  would offer. If that trust level isn't acceptable for your deployment,
  the alternative is compiling qpdf to WASM yourself from the official
  https://github.com/qpdf/qpdf source with Emscripten.

SHA-256 checksums at fetch time, for detecting future tampering of these
vendored files:

```
lib/qpdf.wasm:    86cba3db67ce3add2dd4b3533dd0614dade0b4e98b14a229bfda90306c053dd3
lib/qpdf.js:      35df3cad3919f370dd86970e1ea3fc8bd57f744be23a50a773f17abcbf1d9ffc
qpdf-worker.js:   a143e04ba70999c18ac6d24bc76a6a07ddc43dfe88c92c02d742a19d90f2ea66
```

The main-thread orchestration talking to this worker is our own code (see
`src/lib/qpdfClient.ts`), not vendored — only the compiled qpdf engine
itself and its message-loop worker script are third-party.
