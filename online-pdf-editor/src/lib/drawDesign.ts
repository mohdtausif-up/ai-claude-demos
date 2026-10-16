/**
 * Draws watermark/page-number/header-footer text for one page, and the flat
 * background fill. Shares FontCache with flattenAnnotations.ts so a document
 * using both annotations and design elements only embeds each standard font
 * once.
 *
 * Background needs different treatment: pdf-lib only ever *appends* drawing
 * operators to a page's content stream, so anything drawn via drawRectangle
 * here would paint on TOP of the page's existing content, not behind it. See
 * pdfBuild.ts for how background pages are rebuilt (original content embedded
 * as a Form XObject on top of a fresh page whose background is drawn first)
 * so the fill genuinely sits behind the page's content.
 */
import { degrees, type PDFFont, type PDFPage } from 'pdf-lib';
import { DEFAULT_FONT } from './annotations';
import type { FontCache } from './flattenAnnotations';
import { hexToColor } from './color';
import type {
  BackgroundSettings,
  DesignSettings,
  HeaderFooterSettings,
  HorizontalPosition,
  PageNumberSettings,
  PlaceholderContext,
  WatermarkSettings,
} from './design';
import { pageSelected, substitutePlaceholders } from './design';
import type { PdfBox } from './geometry';

/** Distance kept from the page edge for page numbers and header/footer text, in points. */
const MARGIN = 24;

function anchoredX(align: HorizontalPosition, box: PdfBox, width: number): number {
  if (align === 'left') return box.x + MARGIN;
  if (align === 'right') return box.x + box.width - MARGIN - width;
  return box.x + (box.width - width) / 2;
}

async function drawWatermark(page: PDFPage, box: PdfBox, settings: WatermarkSettings, fonts: FontCache, ctx: PlaceholderContext): Promise<void> {
  const text = substitutePlaceholders(settings.text, ctx);
  if (!text) return;
  const font = await fonts.get(DEFAULT_FONT);
  const width = font.widthOfTextAtSize(text, settings.fontSize);
  const rad = (settings.rotation * Math.PI) / 180;
  const cx = box.x + box.width / 2;
  const cy = box.y + box.height / 2;
  // drawText's (x, y) is the baseline's start point, with the glyph run
  // extending toward `rotation`; walking back by half the text's width along
  // that same direction centers it on the page regardless of angle.
  page.drawText(text, {
    x: cx - (width / 2) * Math.cos(rad),
    y: cy - (width / 2) * Math.sin(rad),
    size: settings.fontSize,
    font,
    color: hexToColor(settings.color),
    opacity: settings.opacity,
    rotate: degrees(settings.rotation),
  });
}

async function drawPageNumber(page: PDFPage, box: PdfBox, settings: PageNumberSettings, fonts: FontCache, ctx: PlaceholderContext): Promise<void> {
  const text = substitutePlaceholders(settings.format, ctx);
  if (!text) return;
  const font = await fonts.get(DEFAULT_FONT);
  const width = font.widthOfTextAtSize(text, settings.fontSize);
  const [vertical, horizontal] = settings.position.split('-') as ['top' | 'bottom', HorizontalPosition];
  const y = vertical === 'top' ? box.y + box.height - MARGIN - settings.fontSize : box.y + MARGIN;
  page.drawText(text, { x: anchoredX(horizontal, box, width), y, size: settings.fontSize, font, color: hexToColor(settings.color) });
}

function drawSlot(page: PDFPage, box: PdfBox, y: number, align: HorizontalPosition, template: string, size: number, font: PDFFont, color: ReturnType<typeof hexToColor>, ctx: PlaceholderContext): void {
  const text = substitutePlaceholders(template, ctx);
  if (!text) return;
  const width = font.widthOfTextAtSize(text, size);
  page.drawText(text, { x: anchoredX(align, box, width), y, size, font, color });
}

async function drawHeaderFooter(page: PDFPage, box: PdfBox, settings: HeaderFooterSettings, fonts: FontCache, ctx: PlaceholderContext): Promise<void> {
  const font = await fonts.get(DEFAULT_FONT);
  const color = hexToColor(settings.color);
  const headerY = box.y + box.height - MARGIN - settings.fontSize;
  const footerY = box.y + MARGIN;
  drawSlot(page, box, headerY, 'left', settings.headerLeft, settings.fontSize, font, color, ctx);
  drawSlot(page, box, headerY, 'center', settings.headerCenter, settings.fontSize, font, color, ctx);
  drawSlot(page, box, headerY, 'right', settings.headerRight, settings.fontSize, font, color, ctx);
  drawSlot(page, box, footerY, 'left', settings.footerLeft, settings.fontSize, font, color, ctx);
  drawSlot(page, box, footerY, 'center', settings.footerCenter, settings.fontSize, font, color, ctx);
  drawSlot(page, box, footerY, 'right', settings.footerRight, settings.fontSize, font, color, ctx);
}

/** Draws watermark/page-number/header-footer for one page (background is handled separately — see the module comment). */
export async function drawDesignOverlays(
  page: PDFPage,
  box: PdfBox,
  design: DesignSettings,
  pageIndex: number,
  total: number,
  fonts: FontCache,
  fileName: string,
): Promise<void> {
  const ctx: PlaceholderContext = { page: pageIndex + 1, total, fileName };
  if (design.watermark.enabled && pageSelected(design.watermark.pages, pageIndex, total)) {
    await drawWatermark(page, box, design.watermark, fonts, ctx);
  }
  if (design.pageNumbers.enabled && pageSelected(design.pageNumbers.pages, pageIndex, total)) {
    await drawPageNumber(page, box, design.pageNumbers, fonts, { ...ctx, page: design.pageNumbers.startAt + pageIndex });
  }
  if (design.headerFooter.enabled && pageSelected(design.headerFooter.pages, pageIndex, total)) {
    await drawHeaderFooter(page, box, design.headerFooter, fonts, ctx);
  }
}

export function backgroundApplies(design: DesignSettings | undefined, pageIndex: number, total: number): boolean {
  return !!design?.background.enabled && pageSelected(design.background.pages, pageIndex, total);
}

export function drawBackground(page: PDFPage, box: PdfBox, settings: BackgroundSettings): void {
  page.drawRectangle({ x: box.x, y: box.y, width: box.width, height: box.height, color: hexToColor(settings.color) });
}
