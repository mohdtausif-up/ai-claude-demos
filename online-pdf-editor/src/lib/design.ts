/**
 * Document-wide design settings — watermark, page numbers, header/footer,
 * background — as opposed to per-page annotations (annotations.ts). These are
 * generated fresh at build time from one settings object shared by the whole
 * document (see pdfBuild.ts / drawDesign.ts), rather than stored per page.
 */
import type { HexColor } from './annotations';
import { parsePageList } from './pageRanges';

export type DesignPageSelector = { mode: 'all' } | { mode: 'custom'; range: string };

export type VerticalPosition = 'top' | 'bottom';
export type HorizontalPosition = 'left' | 'center' | 'right';
export type DesignPosition = `${VerticalPosition}-${HorizontalPosition}`;

export interface WatermarkSettings {
  enabled: boolean;
  text: string;
  fontSize: number;
  color: HexColor;
  /** 0 (invisible) .. 1 (opaque). */
  opacity: number;
  /** Degrees, counter-clockwise, applied around the page center. */
  rotation: number;
  pages: DesignPageSelector;
}

export interface PageNumberSettings {
  enabled: boolean;
  /** May include {page}, {total}, {filename}, {date}. */
  format: string;
  position: DesignPosition;
  fontSize: number;
  color: HexColor;
  startAt: number;
  pages: DesignPageSelector;
}

export interface HeaderFooterSettings {
  enabled: boolean;
  headerLeft: string;
  headerCenter: string;
  headerRight: string;
  footerLeft: string;
  footerCenter: string;
  footerRight: string;
  fontSize: number;
  color: HexColor;
  pages: DesignPageSelector;
}

export interface BackgroundSettings {
  enabled: boolean;
  color: HexColor;
  pages: DesignPageSelector;
}

export interface DesignSettings {
  watermark: WatermarkSettings;
  pageNumbers: PageNumberSettings;
  headerFooter: HeaderFooterSettings;
  background: BackgroundSettings;
}

export const DEFAULT_DESIGN_SETTINGS: DesignSettings = {
  watermark: {
    enabled: false,
    text: 'CONFIDENTIAL',
    fontSize: 48,
    color: '#ff0000',
    opacity: 0.3,
    rotation: 45,
    pages: { mode: 'all' },
  },
  pageNumbers: {
    enabled: false,
    format: 'Page {page} of {total}',
    position: 'bottom-center',
    fontSize: 10,
    color: '#000000',
    startAt: 1,
    pages: { mode: 'all' },
  },
  headerFooter: {
    enabled: false,
    headerLeft: '',
    headerCenter: '',
    headerRight: '',
    footerLeft: '',
    footerCenter: '',
    footerRight: '',
    fontSize: 9,
    color: '#000000',
    pages: { mode: 'all' },
  },
  background: {
    enabled: false,
    color: '#ffffff',
    pages: { mode: 'all' },
  },
};

/** Whether 0-based `pageIndex` (out of `total`) is included by a page selector. */
export function pageSelected(selector: DesignPageSelector, pageIndex: number, total: number): boolean {
  if (selector.mode === 'all') return true;
  try {
    return parsePageList(selector.range, total).includes(pageIndex);
  } catch {
    // An out-of-range or unparsable range for this particular output (e.g. a
    // split file with fewer pages than the range mentions) just means the
    // element doesn't apply here, rather than failing the whole export.
    return false;
  }
}

export interface PlaceholderContext {
  page: number;
  total: number;
  fileName: string;
}

export function substitutePlaceholders(template: string, ctx: PlaceholderContext): string {
  return template
    .replace(/\{page\}/g, String(ctx.page))
    .replace(/\{total\}/g, String(ctx.total))
    .replace(/\{filename\}/g, ctx.fileName)
    .replace(/\{date\}/g, new Date().toLocaleDateString());
}
