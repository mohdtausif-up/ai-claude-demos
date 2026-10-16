import { rgb, type RGB } from 'pdf-lib';

/** "#rgb" or "#rrggbb" → a pdf-lib RGB color (0..1 floats), with `.red/.green/.blue` for raw operator draws. */
export function hexToColor(hex: string): RGB {
  const clean = hex.replace('#', '');
  const full = clean.length === 3 ? clean.split('').map((c) => c + c).join('') : clean.padEnd(6, '0');
  const int = Number.parseInt(full, 16);
  return rgb(((int >> 16) & 255) / 255, ((int >> 8) & 255) / 255, (int & 255) / 255);
}
