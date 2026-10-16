import { contentRectToCanvasBox } from '../../lib/annotateGeometry';
import type { FieldValue, FormFieldInfo } from '../../lib/formFields';
import { totalRotation } from '../../lib/geometry';
import type { PageRef } from '../../lib/pageModel';
import { useDocumentStore } from '../../store/documentStore';
import { setFieldValue } from '../../tools/forms/commands';
import { runCommand } from '../../tools/organize/actions';

interface Props {
  page: PageRef;
  containerSize: { width: number; height: number };
}

const controlClass = 'absolute border border-blue-400 bg-blue-50/70 text-[13px] leading-tight text-slate-900 focus:bg-blue-50 focus:outline-none focus:ring-1 focus:ring-blue-500';

/**
 * Real HTML form controls positioned over the page at each existing AcroForm
 * field's widget rect (same fractional/rotation convention as annotations —
 * see annotateGeometry.ts). Unlike AnnotateOverlay, nothing here is drawn,
 * moved or resized: fields exist wherever the source PDF already placed
 * them; this only lets the user fill in values, which are applied onto the
 * real AcroForm (and optionally flattened) at export time — see
 * lib/formFields.ts and pdfBuild.ts.
 */
export default function FormFillOverlay({ page, containerSize }: Props) {
  const formValues = useDocumentStore((s) => s.doc?.formValues ?? {});
  const rotation = totalRotation(page);
  const fields = page.base.fields;
  if (fields.length === 0) return null;

  const commit = (name: string, value: FieldValue) => runCommand(setFieldValue(name, value));
  const currentValue = (field: FormFieldInfo): FieldValue => formValues[field.name] ?? field.value;

  return (
    <div className="absolute inset-0">
      {fields.map((field, i) => {
        const box = contentRectToCanvasBox(field.rect, rotation, containerSize);
        const style = { left: box.left, top: box.top, width: box.width, height: box.height };
        const key = `${field.name}-${i}`;

        if (field.readOnly) return null;

        if (field.type === 'text') {
          const value = String(currentValue(field));
          return field.multiline ? (
            <textarea
              key={key}
              data-testid="form-field"
              data-field-name={field.name}
              defaultValue={value}
              style={style}
              onBlur={(e) => commit(field.name, e.target.value)}
              className={`${controlClass} resize-none px-1 py-0.5`}
            />
          ) : (
            <input
              key={key}
              type="text"
              data-testid="form-field"
              data-field-name={field.name}
              defaultValue={value}
              style={style}
              onBlur={(e) => commit(field.name, e.target.value)}
              className={`${controlClass} px-1`}
            />
          );
        }

        if (field.type === 'checkbox') {
          return (
            <input
              key={key}
              type="checkbox"
              data-testid="form-field"
              data-field-name={field.name}
              defaultChecked={!!currentValue(field)}
              style={style}
              onChange={(e) => commit(field.name, e.target.checked)}
              className="absolute cursor-pointer accent-blue-600"
            />
          );
        }

        if (field.type === 'radio') {
          return (
            <input
              key={key}
              type="radio"
              name={field.name}
              data-testid="form-field"
              data-field-name={field.name}
              defaultChecked={currentValue(field) === field.widgetOptionValue}
              style={style}
              onChange={(e) => e.target.checked && field.widgetOptionValue && commit(field.name, field.widgetOptionValue)}
              className="absolute cursor-pointer accent-blue-600"
            />
          );
        }

        // dropdown / listbox
        const value = String(currentValue(field));
        return (
          <select
            key={key}
            data-testid="form-field"
            data-field-name={field.name}
            defaultValue={value}
            style={style}
            onChange={(e) => commit(field.name, e.target.value)}
            className={`${controlClass} px-0.5`}
          >
            {!field.options?.includes(value) && <option value={value}>{value || '—'}</option>}
            {field.options?.map((opt) => (
              <option key={opt} value={opt}>
                {opt}
              </option>
            ))}
          </select>
        );
      })}
    </div>
  );
}
