import { describe, expect, it } from 'vitest';
import { pageSelected, substitutePlaceholders } from '../design';

describe('pageSelected', () => {
  it('matches every page in "all" mode', () => {
    expect(pageSelected({ mode: 'all' }, 0, 5)).toBe(true);
    expect(pageSelected({ mode: 'all' }, 4, 5)).toBe(true);
  });

  it('matches only the listed 0-based pages in "custom" mode', () => {
    const selector = { mode: 'custom' as const, range: '1-2, 4' };
    expect(pageSelected(selector, 0, 5)).toBe(true);
    expect(pageSelected(selector, 1, 5)).toBe(true);
    expect(pageSelected(selector, 2, 5)).toBe(false);
    expect(pageSelected(selector, 3, 5)).toBe(true);
  });

  it('treats an out-of-range or unparsable range as "doesn\'t apply" rather than throwing', () => {
    expect(pageSelected({ mode: 'custom', range: '1-10' }, 0, 3)).toBe(false);
    expect(pageSelected({ mode: 'custom', range: 'not a range' }, 0, 3)).toBe(false);
    expect(pageSelected({ mode: 'custom', range: '' }, 0, 3)).toBe(false);
  });
});

describe('substitutePlaceholders', () => {
  it('substitutes page, total and filename', () => {
    const result = substitutePlaceholders('{page} of {total} — {filename}', { page: 2, total: 5, fileName: 'report.pdf' });
    expect(result).toBe('2 of 5 — report.pdf');
  });

  it('leaves text without placeholders untouched', () => {
    expect(substitutePlaceholders('CONFIDENTIAL', { page: 1, total: 1, fileName: 'a.pdf' })).toBe('CONFIDENTIAL');
  });
});
