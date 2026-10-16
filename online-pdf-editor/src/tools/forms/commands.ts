/** Undoable edits to AcroForm field values and the flatten-on-export toggle. */
import type { FieldValue } from '../../lib/formFields';
import { makeCommand, type Command } from '../../store/history';

export function setFieldValue(name: string, value: FieldValue): Command {
  return makeCommand(`Fill "${name}"`, (state) => ({ ...state, formValues: { ...state.formValues, [name]: value } }));
}

export function setFlattenForms(flattenForms: boolean): Command {
  return makeCommand(flattenForms ? 'Flatten form fields on export' : 'Keep form fields fillable on export', (state) => ({ ...state, flattenForms }));
}
