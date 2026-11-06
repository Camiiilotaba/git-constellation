import { readFileSync } from 'node:fs';
import type { ConstellationData, ContributionDay } from '../../renderer/types.js';

const fixture = JSON.parse(readFileSync(new URL('../../fixtures/demo.json', import.meta.url), 'utf8')) as {
  period: ConstellationData['period']; days: ContributionDay[]; pullRequests: number; codeReviews: number;
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
    schemaVersion: 1,
    mode: 'demo',
    profile: { username: 'demo-developer', name: 'Alex Morgan', role: 'Backend Developer', stack: 'PHP / TypeScript' },
    theme: { cyan: '#30e8ef', green: '#52f5a0' },
    period: { ...fixture.period },
    days: structuredClone(fixture.days),
    metrics: { totalContributions: 0, activeDays: 0, longestStreak: 0, pullRequests: fixture.pullRequests, codeReviews: fixture.codeReviews },
    languages: [
      { name: 'PHP', repositories: 8, color: '#8580d5' },
      { name: 'JavaScript', repositories: 6, color: '#f1e05a' },
      { name: 'TypeScript', repositories: 4, color: '#3178c6' },
      { name: 'Python', repositories: 3, color: '#40b9ca' },
      { name: 'SQL', repositories: 2, color: '#24b978' },
    ],
    privateContributionsIncluded: false,
  });
}

export function shortData(counts: number[] = [1, 0, 2, 3, 0]): ConstellationData {
  const data = demoData();
  data.days = counts.map((count, index) => ({ date: `2025-11-${String(index + 1).padStart(2, '0')}`, count }));
  data.period.end = data.days.at(-1)!.date;
  return recalculate(data);
}
