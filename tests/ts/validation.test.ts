import { describe, expect, it } from 'vitest';
import { parseDate, validateData } from '../../renderer/validation.js';
import { demoData, shortData } from './fixtures.js';

describe('offline data boundary', () => {
  it('accepts a complete fixture and nullable unavailable metrics', () => {
    const data = demoData();
    data.metrics.pullRequests = null;
    data.metrics.codeReviews = null;
    expect(validateData(data)).toEqual(data);
  });

  it.each(['2025-02-29', '2026-02-30', '0000-01-01', '2026-13-01', '2026-1-01', 'not-a-date'])('rejects invalid calendar date %s', date => {
    expect(() => parseDate(date)).toThrow();
  });

  it('rejects missing, duplicate, unordered and noncontiguous days', () => {
    const missing = shortData(); missing.days.pop();
    expect(() => validateData(missing)).toThrow(/every day/);
    const duplicate = shortData(); duplicate.days[1]!.date = duplicate.days[0]!.date;
    expect(() => validateData(duplicate)).toThrow(/contiguous/);
    const unordered = shortData(); unordered.days.reverse();
    expect(() => validateData(unordered)).toThrow(/contiguous/);
  });

  it.each([-1, .5, Number.MAX_SAFE_INTEGER + 1, NaN, Infinity])('rejects invalid count %s', count => {
    const data = shortData(); data.days[0]!.count = count;
    expect(() => validateData(data)).toThrow(/safe integer/);
  });

  it('rejects excessive periods, reversed periods and sum overflow', () => {
    const data = shortData(); data.period.end = '2026-11-02';
    expect(() => validateData(data)).toThrow(/366/);
    data.period.end = '2025-10-31';
    expect(() => validateData(data)).toThrow(/366/);
    const overflow = shortData(); overflow.days[0]!.count = Number.MAX_SAFE_INTEGER; overflow.days[1]!.count = 1;
    expect(() => validateData(overflow)).toThrow(/safe integer range/);
  });

  it('accepts an inclusive leap-year calendar of 366 days', () => {
    const data = demoData();
    data.period = { start: '2024-01-01', end: '2024-12-31' };
    data.days = Array.from({ length: 366 }, (_, i) => ({ date: new Date(Date.UTC(2024, 0, 1 + i)).toISOString().slice(0, 10), count: 0 }));
    Object.assign(data.metrics, { totalContributions: 0, activeDays: 0, longestStreak: 0 });
    expect(validateData(data).days).toHaveLength(366);
  });

  it.each(['totalContributions', 'activeDays', 'longestStreak'] as const)('rejects a conflicting %s metric', metric => {
    const data = shortData(); data.metrics[metric]++;
    expect(() => validateData(data)).toThrow(/agree/);
  });

  it.each(['#fff', 'red', '#12345Z', 'url(https://example.com)', '#123456; background:red'])('rejects unsafe theme value %s', value => {
    const data = shortData(); data.theme.cyan = value;
    expect(() => validateData(data)).toThrow(/hexadecimal/);
  });

  it('rejects invalid language colors, duplicates and fabricated empty ranks', () => {
    const data = shortData(); data.languages[0]!.color = 'red';
    expect(() => validateData(data)).toThrow(/hexadecimal/);
    data.languages[0]!.color = '#abcdef'; data.languages[1]!.name = 'php';
    expect(() => validateData(data)).toThrow(/duplicates/);
    data.languages.pop(); data.languages[1]!.name = 'JavaScript'; data.languages[0]!.repositories = 0;
    expect(() => validateData(data)).toThrow(/at least one/);
  });

  it('rejects unsupported modes and private claims in demonstration data', () => {
    expect(() => validateData({ ...shortData(), mode: 'live' })).toThrow(/demo/);
    expect(() => validateData({ ...shortData(), privateContributionsIncluded: true })).toThrow(/must not claim private/);
    expect(() => validateData({ ...shortData(), schemaVersion: 2 })).toThrow(/schemaVersion/);
  });

  it('reports incomplete fields without echoing data', () => {
    const data: Record<string, unknown> = { ...shortData(), profile: { name: 'SENSITIVE_SENTINEL' } };
    expect(() => validateData(data)).toThrow(/profile.username/);
    try { validateData(data); } catch (error) { expect(String(error)).not.toContain('SENSITIVE_SENTINEL'); }
    expect(() => validateData(null)).toThrow(/object/);
  });
});
