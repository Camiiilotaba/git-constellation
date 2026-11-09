import { describe, expect, it } from 'vitest';
import { parseDate, validateData } from '../../renderer/validation.js';
import { allTimeData, demoData, liveData, manualLanguages, shortData } from './fixtures.js';

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
    data.period = { start: '2024-01-01', end: '2024-12-31', kind: 'last-year', granularity: 'week' };
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

  it('accepts a manual language list and rejects invalid source or repository values', () => {
    const data = shortData();
    data.languagesSource = 'manual';
    data.languages = manualLanguages();
    expect(() => validateData(data)).not.toThrow();
    data.languages[0]!.repositories = 3;
    expect(() => validateData(data)).toThrow(/null/);
    data.languages = manualLanguages();
    data.languages = [...data.languages, ...data.languages.map(l => ({ ...l, name: `${l.name}2` }))];
    expect(() => validateData(data)).toThrow(/at most five/);
    data.languages = [];
    expect(() => validateData(data)).toThrow(/1 to 5/);
    const ranking = shortData();
    ranking.languages[0]!.repositories = null;
    expect(() => validateData(ranking)).toThrow(/nonnegative/);
    expect(() => validateData({ ...shortData(), languagesSource: 'other' })).toThrow(/languagesSource/);
    const missing: Record<string, unknown> = { ...shortData() };
    delete missing.languagesSource;
    expect(() => validateData(missing)).toThrow(/languagesSource/);
  });

  it('accepts schema v3 demo data', () => { expect(() => validateData(demoData())).not.toThrow(); });
  it('rejects schemaVersion 1', () => { expect(() => validateData({ ...demoData(), schemaVersion: 1 })).toThrow(/schemaVersion/); });
  it('rejects schemaVersion 2', () => { expect(() => validateData({ ...demoData(), schemaVersion: 2 })).toThrow(/schemaVersion/); });

  it('accepts an all-time monthly period longer than 366 days', () => {
    expect(validateData(allTimeData()).days).toHaveLength(1114);
  });

  it('keeps the 366-day limit for weekly periods only', () => {
    const data = allTimeData();
    data.period.granularity = 'week';
    expect(() => validateData(data)).toThrow(/366/);
  });

  it('rejects an unknown period kind or granularity', () => {
    expect(() => validateData({ ...demoData(), period: { ...demoData().period, kind: 'calendar-year' as never } })).toThrow(/period.kind/);
    expect(() => validateData({ ...demoData(), period: { ...demoData().period, granularity: 'day' as never } })).toThrow(/period.granularity/);
    const { kind: _kind, ...withoutKind } = demoData().period;
    expect(() => validateData({ ...demoData(), period: withoutKind as never })).toThrow(/period.kind/);
  });
  it('requires a boolean source.ownAccount in live mode', () => {
    const source = liveData().source!;
    expect(() => validateData(liveData({ source: { ...source, ownAccount: 'yes' as unknown as boolean } }))).toThrow(/source.ownAccount/);
  });

  it('accepts live data with each privateContributions state', () => {
    for (const state of ['included', 'excluded', 'unknown'] as const) {
      expect(() => validateData(liveData({ privateContributions: state }))).not.toThrow();
    }
  });
  it('rejects included/unknown in demo mode', () => {
    expect(() => validateData({ ...demoData(), privateContributions: 'included' })).toThrow(/demo/i);
    expect(() => validateData({ ...demoData(), privateContributions: 'unknown' })).toThrow(/demo/i);
  });
  it('requires source in live mode and null source in demo mode', () => {
    expect(() => validateData(liveData({ source: null }))).toThrow(/source/);
    expect(() => validateData({ ...demoData(), source: liveData().source })).toThrow(/source/);
  });
  it('rejects a malformed live source', () => {
    const source = { ...liveData().source!, provider: 'gitlab' };
    expect(() => validateData(liveData({ source: source as never }))).toThrow(/source.provider/);
    expect(() => validateData(liveData({ source: { ...liveData().source!, login: 'x'.repeat(40) } }))).toThrow(/source.login/);
    expect(() => validateData(liveData({ source: { ...liveData().source!, repositoriesScanned: -1 } }))).toThrow(/repositoriesScanned/);
  });
  it('validates generatedAt as ISO 8601 UTC', () => {
    expect(() => validateData({ ...demoData(), generatedAt: '2026-11-06 12:00' })).toThrow(/generatedAt/);
  });
  it('rejects an unknown mode', () => { expect(() => validateData({ ...demoData(), mode: 'test' })).toThrow(/mode/); });

  it('reports incomplete fields without echoing data', () => {
    const data: Record<string, unknown> = { ...shortData(), profile: { name: 'SENSITIVE_SENTINEL' } };
    expect(() => validateData(data)).toThrow(/profile.username/);
    try { validateData(data); } catch (error) { expect(String(error)).not.toContain('SENSITIVE_SENTINEL'); }
    expect(() => validateData(null)).toThrow(/object/);
  });
});
