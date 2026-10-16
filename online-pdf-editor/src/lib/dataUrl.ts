/** Decodes a `data:<mime>;base64,<data>` URL into raw bytes and its MIME type. */
export function parseDataUrl(dataUrl: string): { mime: string; bytes: Uint8Array } {
  const match = /^data:([^;,]+)(?:;[^,]*)?,(.*)$/s.exec(dataUrl);
  if (!match) throw new Error('Not a valid data: URL.');
  const [, mime, base64] = match;
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return { mime, bytes };
}
