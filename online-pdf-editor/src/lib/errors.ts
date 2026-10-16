export type PdfErrorCode =
  | 'not-pdf'
  | 'corrupt'
  | 'encrypted'
  | 'empty'
  | 'too-large'
  | 'build-failed'
  | 'cancelled'
  | 'unknown';

/** An error whose message is safe and meaningful to show to the user. */
export class PdfError extends Error {
  readonly code: PdfErrorCode;
  constructor(code: PdfErrorCode, message: string) {
    super(message);
    this.name = 'PdfError';
    this.code = code;
  }
}

export function isCancelled(err: unknown): boolean {
  return err instanceof PdfError && err.code === 'cancelled';
}

export function toUserMessage(err: unknown): string {
  if (err instanceof PdfError) return err.message;
  if (err instanceof Error && err.name === 'RangeParseError') return err.message;
  return 'Something went wrong while processing the file. It may be damaged or use an unsupported PDF feature.';
}

/** Serializable form, for passing errors across the worker boundary. */
export interface SerializedError {
  code: PdfErrorCode;
  message: string;
}

export function serializeError(err: unknown): SerializedError {
  if (err instanceof PdfError) return { code: err.code, message: err.message };
  return { code: 'unknown', message: toUserMessage(err) };
}
