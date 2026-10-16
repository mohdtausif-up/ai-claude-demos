export class RangeParseError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'RangeParseError';
  }
}

/**
 * Parses a page-range expression like "1-3, 5, 8-" into groups of 0-based page
 * indices. Each comma-separated part becomes one group. Supports open ranges
 * ("8-" = 8 to last, "-3" = 1 to 3), descending ranges ("5-3") and "last"/"end".
 */
export function parseRangeGroups(input: string, pageCount: number): number[][] {
  const text = input.trim();
  if (!text) throw new RangeParseError('Enter at least one page or range, e.g. "1-3, 5".');

  const groups: number[][] = [];
  for (const rawPart of text.split(/[,;]/)) {
    const part = rawPart.trim().toLowerCase();
    if (!part) continue;

    const toPage = (s: string, fallback: number): number => {
      const t = s.trim();
      if (t === '') return fallback;
      if (t === 'last' || t === 'end') return pageCount;
      if (!/^\d+$/.test(t)) throw new RangeParseError(`"${rawPart.trim()}" is not a valid page or range.`);
      const n = Number(t);
      if (n < 1 || n > pageCount) {
        throw new RangeParseError(`Page ${n} doesn't exist — this document has ${pageCount} page${pageCount === 1 ? '' : 's'}.`);
      }
      return n;
    };

    const dash = part.indexOf('-');
    if (dash === -1) {
      groups.push([toPage(part, NaN) - 1]);
      continue;
    }
    if (part.indexOf('-', dash + 1) !== -1) {
      throw new RangeParseError(`"${rawPart.trim()}" is not a valid range.`);
    }
    const start = toPage(part.slice(0, dash), 1);
    const end = toPage(part.slice(dash + 1), pageCount);
    const group: number[] = [];
    const step = start <= end ? 1 : -1;
    for (let p = start; p !== end + step; p += step) group.push(p - 1);
    groups.push(group);
  }
  if (groups.length === 0) throw new RangeParseError('Enter at least one page or range, e.g. "1-3, 5".');
  return groups;
}

/** Flat list of 0-based indices, in the order written (duplicates kept). */
export function parsePageList(input: string, pageCount: number): number[] {
  return parseRangeGroups(input, pageCount).flat();
}

/** Groups for "split every N pages". */
export function splitEvery(pageCount: number, n: number): number[][] {
  if (!Number.isInteger(n) || n < 1) throw new RangeParseError('Pages per file must be a whole number of at least 1.');
  const groups: number[][] = [];
  for (let start = 0; start < pageCount; start += n) {
    groups.push(Array.from({ length: Math.min(n, pageCount - start) }, (_, i) => start + i));
  }
  return groups;
}

/** Formats 0-based indices as a compact 1-based range string, e.g. "1-3, 5". */
export function formatPageList(indices: number[]): string {
  const sorted = [...new Set(indices)].sort((a, b) => a - b);
  const parts: string[] = [];
  for (let i = 0; i < sorted.length; ) {
    let j = i;
    while (j + 1 < sorted.length && sorted[j + 1] === sorted[j] + 1) j++;
    parts.push(i === j ? `${sorted[i] + 1}` : `${sorted[i] + 1}-${sorted[j] + 1}`);
    i = j + 1;
  }
  return parts.join(', ');
}
