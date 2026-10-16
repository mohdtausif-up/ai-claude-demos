import type { SerializedError } from './errors';
import type { DesignSettings } from './design';
import type { FieldValue } from './formFields';
import type { OutputSpec } from './pdfBuild';
import type { SourcePageInfo } from './pageModel';

export type JobRequest =
  | { type: 'inspect'; bytes: Uint8Array; name: string }
  | {
      type: 'build';
      sources: Record<string, Uint8Array>;
      sourceNames: Record<string, string>;
      outputs: OutputSpec[];
      /** When set and there are several outputs, return one ZIP with this name. */
      zipName?: string;
      design?: DesignSettings;
      formValues?: Record<string, FieldValue>;
      flattenForms?: boolean;
    };

export interface OutputFile {
  name: string;
  bytes: Uint8Array;
  mime: string;
}

export type JobResult = { kind: 'inspect'; pages: SourcePageInfo[] } | { kind: 'files'; files: OutputFile[] };

export type JobResponse =
  | { type: 'progress'; value: number; label?: string }
  | { type: 'done'; result: JobResult }
  | { type: 'error'; error: SerializedError };
