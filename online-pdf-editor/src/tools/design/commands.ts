/** Undoable edits to the document-wide design settings (watermark/page numbers/header-footer/background). */
import type { DesignSettings } from '../../lib/design';
import { makeCommand, type Command } from '../../store/history';

export function updateDesign(design: DesignSettings): Command {
  return makeCommand('Update design settings', (state) => ({ ...state, design }));
}
