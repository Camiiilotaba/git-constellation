import { readFileSync } from 'node:fs';
import type { ConstellationData, ContributionDay } from '../../renderer/types.js';

const fixture = JSON.parse(readFileSync(new URL('../../fixtures/demo.json', import.meta.url), 'utf8')) as {
  period: { start: string; end: string }; days: ContributionDay[]; pullRequests: number; codeReviews: number;
};

/** Test-only data preparation; production metrics are supplied by PHP. */
export function recalculate(data: ConstellationData): ConstellationData {
  let streak = 0;
  let longestStreak = 0;
  for (const day of data.days) {
    streak = day.count > 0 ? streak + 1 : 0;
    longestStreak = Math.max(longestStreak, streak);
  }
  data.metrics.totalContributions = data.days.reduce((sum, day) => sum + day.count, 0);
  data.metrics.activeDays = data.days.filter(day => day.count > 0).length;
  data.metrics.longestStreak = longestStreak;
  return data;
}

export function demoData(): ConstellationData {
  return recalculate({
    schemaVersion: 3,
    mode: 'demo',
    generatedAt: '2026-11-06T12:00:00Z',
    profile: { username: 'demo-developer', name: 'Alex Morgan', role: 'Backend Developer', stack: 'PHP / TypeScript' },
    theme: { cyan: '#30e8ef', green: '#52f5a0' },
    period: { ...fixture.period, kind: 'last-year', granularity: 'week' },
    days: structuredClone(fixture.days),
    metrics: { totalContributions: 0, activeDays: 0, longestStreak: 0, pullRequests: fixture.pullRequests, codeReviews: fixture.codeReviews },
    languages: [
      { name: 'PHP', repositories: 8, color: '#8580d5' },
      { name: 'JavaScript', repositories: 6, color: '#f1e05a' },
      { name: 'TypeScript', repositories: 4, color: '#3178c6' },
      { name: 'Python', repositories: 3, color: '#40b9ca' },
      { name: 'SQL', repositories: 2, color: '#24b978' },
    ],
    languagesSource: 'ranking',
    privateContributions: 'excluded',
    source: null,
  });
}

export function liveData(overrides: Partial<ConstellationData> = {}): ConstellationData {
  return {
    ...demoData(),
    mode: 'live',
    privateContributions: 'included',
    source: {
      provider: 'github',
      login: 'demo-developer',
      restrictedContributions: 20,
      ownAccount: false,
      visibleTotals: { commits: 50, issues: 10, pullRequests: 20, reviews: 15, repositories: 5 },
      repositoriesScanned: 12,
    },
    ...overrides,
  };
}

export function shortData(counts: number[] = [1, 0, 2, 3, 0]): ConstellationData {
  const data = demoData();
  data.days = counts.map((count, index) => ({ date: `2025-11-${String(index + 1).padStart(2, '0')}`, count }));
  data.period.end = data.days.at(-1)!.date;
  return recalculate(data);
}

export function manualLanguages(): ConstellationData['languages'] {
  return [
    { name: 'TypeScript', repositories: null, color: '#3178c6' },
    { name: 'PHP', repositories: null, color: '#8580d5' },
    { name: 'Rust', repositories: null, color: '#dea584' },
  ];
}

/** Live all-time data from 2023-10-23 to 2026-11-09 with a deterministic, growing activity pattern. */
export function allTimeData(): ConstellationData {
  const data = liveData({ privateContributions: 'included' });
  data.period = { start: '2023-10-23', end: '2026-11-09', kind: 'all-time', granularity: 'month' };
  data.days = [];
  for (let time = Date.parse('2023-10-23T00:00:00Z'), index = 0; time <= Date.parse('2026-11-09T00:00:00Z'); time += 86_400_000, index++) {
    const weekday = new Date(time).getUTCDay();
    const wave = Math.round(2 + 1.5 * Math.sin(index / 45) + index / 400);
    data.days.push({ date: new Date(time).toISOString().slice(0, 10), count: weekday === 0 || (index * 7) % 11 === 0 ? 0 : wave });
  }
  data.days[0]!.count = 3;
  data.metrics.pullRequests = 250;
  data.metrics.codeReviews = 274;
  return recalculate(data);
}
