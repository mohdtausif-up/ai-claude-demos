/**
 * AcroForm field discovery and filling. Extraction (extractFormFields) reads
 * a source PDF's existing form fields at import time, independent of this
 * app's PageRef abstraction — it just needs to know which page each field's
 * widget appears on and where, in the same fractional, un-rotated coordinate
 * convention annotations and crop already use (see geometry.ts).
 *
 * Only fill-in-the-blanks field types are supported: text, checkbox, radio,
 * dropdown, listbox. Push buttons and real cryptographic /Sig fields are
 * skipped entirely — there is no client-side way to fill either meaningfully
 * here, and fabricating support would violate CLAUDE.md's anti-fabrication
 * rule. Creating NEW fields (a "form designer") is not implemented at all.
 */
import {
  PDFArray,
  PDFCheckBox,
  PDFDict,
  PDFDropdown,
  PDFField,
  PDFName,
  PDFNumber,
  PDFOptionList,
  PDFRadioGroup,
  PDFRef,
  PDFTextField,
  type PDFDocument,
  type PDFPage,
} from 'pdf-lib';
import { pdfBoxToFracRect, type PdfBox } from './geometry';
import type { FracRect } from './pageModel';

export type FormFieldType = 'text' | 'checkbox' | 'radio' | 'dropdown' | 'listbox';

export type FieldValue = string | boolean;

export interface FormFieldInfo {
  name: string;
  type: FormFieldType;
  /** In this page's original, un-rotated, un-cropped space — same convention as AnnotationObject.rect. */
  rect: FracRect;
  value: FieldValue;
  /** Choices for radio/dropdown/listbox. */
  options?: string[];
  multiline?: boolean;
  readOnly: boolean;
  /**
   * Radio groups have one widget (and so one FormFieldInfo) per on-screen
   * button, all sharing `name` and `options` — this is the specific option
   * *this* widget/rect represents, so the fill UI can render a real radio
   * button per rect instead of one control per field.
   */
  widgetOptionValue?: string;
}

/** Reads every fillable field in `doc`, grouped by the index of the page each widget appears on. */
export function extractFormFields(doc: PDFDocument, pages: PDFPage[]): FormFieldInfo[][] {
  const perPage: FormFieldInfo[][] = pages.map(() => []);
  let fields;
  try {
    fields = doc.getForm().getFields();
  } catch {
    return perPage; // no AcroForm at all
  }

  const pageIndexByRefTag = new Map(pages.map((p, i) => [p.ref.tag, i]));

  for (const field of fields) {
    try {
      let type: FormFieldType;
      let value: FieldValue;
      let options: string[] | undefined;
      let multiline = false;
      if (field instanceof PDFTextField) {
        type = 'text';
        value = field.getText() ?? '';
        multiline = field.isMultiline();
      } else if (field instanceof PDFCheckBox) {
        type = 'checkbox';
        value = field.isChecked();
      } else if (field instanceof PDFRadioGroup) {
        type = 'radio';
        value = field.getSelected() ?? '';
        options = field.getOptions();
      } else if (field instanceof PDFDropdown) {
        type = 'dropdown';
        value = field.getSelected()[0] ?? '';
        options = field.getOptions();
      } else if (field instanceof PDFOptionList) {
        type = 'listbox';
        value = field.getSelected()[0] ?? '';
        options = field.getOptions();
      } else {
        continue; // push button, signature field, or something unsupported
      }

      const name = field.getName();
      const readOnly = field.isReadOnly();
      const widgets = field.acroField.getWidgets();
      widgets.forEach((widget, widgetIndex) => {
        const pageRef = widget.P();
        const pageIndex = pageRef ? pageIndexByRefTag.get(pageRef.tag) : undefined;
        if (pageIndex === undefined) return; // widget with no /P back-reference — rare, skipped rather than guessed at
        const page = pages[pageIndex];
        const box = page.getCropBox();
        const rect = pdfBoxToFracRect(widget.getRectangle(), box);
        // A radio group's getOptions() is index-aligned with its widgets (it
        // resolves the human-readable /Opt export value per widget, not the
        // raw, often auto-generated AP-state name widget.getOnValue() returns
        // — e.g. pdf-lib's own addOptionToPage() names those '0'/'1').
        const widgetOptionValue = type === 'radio' ? options?.[widgetIndex] : undefined;
        perPage[pageIndex].push({ name, type, rect, value, options, multiline, readOnly, widgetOptionValue });
      });
    } catch {
      // One malformed field must not take down the whole import.
    }
  }
  return perPage;
}

/**
 * pdf-lib's copyPages() copies each page's widget annotations (so filled-in
 * forms still *render* correctly), but — being a page-level operation — it
 * never touches the catalog-level /AcroForm dictionary, so the output
 * document ends up with no registered fields at all (out.getForm() would
 * see nothing). The widgets' field data isn't lost, though: copying a
 * widget's dict copies everything it references, generically, including a
 * /Parent entry — so a radio group's root field dict (with its own /Kids
 * list) rides along as a side effect of copying any one of its kid widgets.
 * This walks every copied page's widgets up to their root field and
 * registers those roots into the output's own /AcroForm, so the already-
 * copied objects just need pointing at, not re-copying.
 */
export function reconcileCopiedFormFields(out: PDFDocument): void {
  const registered = new Set<string>();
  let form;
  try {
    form = out.getForm();
  } catch {
    return;
  }
  for (const page of out.getPages()) {
    const annots = page.node.Annots();
    if (!annots) continue;
    for (let i = 0; i < annots.size(); i++) {
      const ref = annots.get(i);
      if (!(ref instanceof PDFRef)) continue;
      const dict = out.context.lookup(ref);
      if (!(dict instanceof PDFDict) || dict.get(PDFName.of('Subtype'))?.toString() !== '/Widget') continue;

      let rootRef = ref;
      let rootDict: PDFDict = dict;
      for (;;) {
        const parentRef = rootDict.get(PDFName.of('Parent'));
        if (!(parentRef instanceof PDFRef)) break;
        const parentDict = out.context.lookup(parentRef);
        if (!(parentDict instanceof PDFDict)) break;
        rootRef = parentRef;
        rootDict = parentDict;
      }

      if (registered.has(rootRef.tag)) continue;
      registered.add(rootRef.tag);
      form.acroForm.addField(rootRef);
    }
  }
}

/** Applies user-entered values onto the output document's (already copied) form, keyed by field name. */
export function applyFormValues(doc: PDFDocument, values: Record<string, FieldValue>): void {
  if (Object.keys(values).length === 0) return;
  let form;
  try {
    form = doc.getForm();
  } catch {
    return;
  }
  // A page list can legitimately repeat the same source page (e.g. extracting
  // "1, 1-2, 1" — see pdfBuild.ts's per-source copy "rounds"), and each repeat
  // gets its own independent copy of that page's widgets/field dict, so the
  // same field name can end up registered more than once in the output form.
  // form.getFieldMaybe(name) only ever returns the first match, so apply to
  // every field sharing a name or the other copies silently keep their old value.
  const byName = new Map<string, PDFField[]>();
  for (const field of form.getFields()) {
    const list = byName.get(field.getName());
    if (list) list.push(field);
    else byName.set(field.getName(), [field]);
  }
  for (const [name, value] of Object.entries(values)) {
    for (const field of byName.get(name) ?? []) {
      try {
        if (field instanceof PDFTextField) field.setText(typeof value === 'string' ? value : '');
        else if (field instanceof PDFCheckBox) (value ? field.check() : field.uncheck());
        else if (field instanceof PDFRadioGroup && typeof value === 'string' && value) field.select(value);
        else if (field instanceof PDFDropdown && typeof value === 'string' && value) field.select(value);
        else if (field instanceof PDFOptionList && typeof value === 'string' && value) field.select(value);
      } catch {
        // A single field refusing a value (e.g. one not among its options) must not fail the whole export.
      }
    }
  }
}

/**
 * When a page's background is applied, pdfBuild.ts re-embeds the page's
 * visible content as a Form XObject onto a freshly created page (see the
 * comment there) — that embed only carries the content stream, not the
 * page's /Annots, so a page with fillable AcroForm fields would otherwise
 * silently lose them. This moves each Widget annotation over to the new
 * page, shifting its /Rect so it lines up with the new page's (0,0)-origin
 * coordinate system (the old page's `srcBox` origin maps to the new origin).
 */
export function carryFormWidgets(srcPage: PDFPage, destPage: PDFPage, srcBox: PdfBox): void {
  const annots = srcPage.node.Annots();
  if (!annots) return;
  const context = srcPage.doc.context;
  for (let i = 0; i < annots.size(); i++) {
    const ref = annots.get(i);
    if (!(ref instanceof PDFRef)) continue;
    const dict = context.lookup(ref);
    if (!(dict instanceof PDFDict) || dict.get(PDFName.of('Subtype'))?.toString() !== '/Widget') continue;

    const rectArr = dict.lookup(PDFName.of('Rect'), PDFArray);
    if (rectArr && rectArr.size() === 4) {
      const nums = rectArr.asArray().map((n) => (n instanceof PDFNumber ? n.asNumber() : 0));
      dict.set(
        PDFName.of('Rect'),
        context.obj([nums[0] - srcBox.x, nums[1] - srcBox.y, nums[2] - srcBox.x, nums[3] - srcBox.y]),
      );
    }
    dict.set(PDFName.of('P'), destPage.ref);
    destPage.node.addAnnot(ref);
  }
}
