import { describe, expect, it } from 'vitest';
import { formatPageList, parsePageList, parseRangeGroups, splitEvery } from '../pageRanges';

describe('parseRangeGroups', () => {
  it('parses single pages, ranges and open ranges into 0-based groups', () => {
    expect(parseRangeGroups('1-3, 5, 8-', 10)).toEqual([[0, 1, 2], [4], [7, 8, 9]]);
    expect(parseRangeGroups('-2', 5)).toEqual([[0, 1]]);
    expect(parseRangeGroups('3-last', 4)).toEqual([[2, 3]]);
  });

  it('supports descending ranges', () => {
    expect(parseRangeGroups('3-1', 3)).toEqual([[2, 1, 0]]);
  });

  it('ignores empty parts and whitespace', () => {
    expect(parseRangeGroups(' 1 ,, 2 ', 3)).toEqual([[0], [1]]);
  });

  it.each([
    ['', /at least one/],
    ['0', /doesn't exist/],
    ['11', /doesn't exist — this document has 10 pages/],
    ['a-b', /not a valid/],
    ['1-2-3', /not a valid range/],
    [',', /at least one/],
  ])('rejects %j', (input, message) => {
    expect(() => parseRangeGroups(input, 10)).toThrow(message);
  });
});

describe('parsePageList', () => {
  it('flattens in written order, keeping duplicates', () => {
    expect(parsePageList('3, 1-2, 3', 3)).toEqual([2, 0, 1, 2]);
  });
});

describe('splitEvery', () => {
  it('chunks with a shorter last group', () => {
    expect(splitEvery(5, 2)).toEqual([[0, 1], [2, 3], [4]]);
  });
  it('rejects invalid chunk sizes', () => {
    expect(() => splitEvery(5, 0)).toThrow();
    expect(() => splitEvery(5, 1.5)).toThrow();
  });
});

describe('formatPageList', () => {
  it('compacts consecutive pages', () => {
    expect(formatPageList([4, 0, 1, 2, 7])).toBe('1-3, 5, 8');
    expect(formatPageList([])).toBe('');
  });
});
