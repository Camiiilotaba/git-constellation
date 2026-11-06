import type { ChartGeometry, ContributionDay, WeeklyPoint } from './types.js';
import { DAY_MS, parseDate } from './validation.js';

export const CHART: ChartGeometry = { left: 155, right: 1480, top: 310, bottom: 478 };

/** Monday UTC buckets; truncated edge weeks retain their actual time midpoint. */
export function weeklyPoints(days: ContributionDay[], geometry: ChartGeometry = CHART): WeeklyPoint[] {
  if (days.length === 0) return [];
  const groups: { start: number; end: number; count: number }[] = [];
  let previousMonday: number | undefined;
  for (const day of days) {
    const timestamp = parseDate(day.date);
    const monday = timestamp - ((new Date(timestamp).getUTCDay() + 6) % 7) * DAY_MS;
    const last = groups[groups.length - 1];
    if (monday !== previousMonday || !last) {
      groups.push({ start: timestamp, end: timestamp, count: day.count });
      previousMonday = monday;
    } else { last.end = timestamp; last.count += day.count; }
  }
  const start = parseDate(days[0]!.date);
  // Day intervals are [00:00, next 00:00), including the last day.
  const duration = parseDate(days[days.length - 1]!.date) + DAY_MS - start;
  const peak = Math.max(...groups.map(group => group.count));
  return groups.map(group => {
    const midpoint = (group.start + group.end + DAY_MS) / 2;
    const intensity = peak === 0 ? 0 : Math.sqrt(group.count / peak);
    return {
      start: new Date(group.start).toISOString().slice(0, 10),
      end: new Date(group.end).toISOString().slice(0, 10),
      count: group.count,
      midpoint,
      intensity,
      x: geometry.left + ((midpoint - start) / duration) * (geometry.right - geometry.left),
      y: geometry.bottom - intensity * (geometry.bottom - geometry.top),
      // Star sizes use the raw weekly ratio; coordinates never use decoration.
      radius: group.count === 0 ? 1.8 : 2.1 + 5.4 * (group.count / peak) ** 1.15,
    };
  });
}

const n = (value: number): string => value.toFixed(2);

/** Monotone Hermite interpolation: smooth without overshooting measured peaks. */
export function smoothPath(points: Pick<WeeklyPoint, 'x' | 'y'>[]): string {
  if (!points.length) return '';
  const first = points[0]!;
  if (points.length === 1) return `M ${n(first.x)} ${n(first.y)}`;
  const slopes = points.slice(1).map((point, i) => (point.y - points[i]!.y) / (point.x - points[i]!.x));
  const tangents = points.map((_, i) => {
    if (i === 0) return slopes[0]!;
    if (i === points.length - 1) return slopes[slopes.length - 1]!;
    const before = slopes[i - 1]!;
    const after = slopes[i]!;
    return before * after <= 0 ? 0 : (2 * before * after) / (before + after);
  });
  let path = `M ${n(first.x)} ${n(first.y)}`;
  for (let i = 1; i < points.length; i++) {
    const previous = points[i - 1]!;
    const point = points[i]!;
    const step = (point.x - previous.x) / 3;
    path += ` C ${n(previous.x + step)} ${n(previous.y + tangents[i - 1]! * step)}, ${n(point.x - step)} ${n(point.y - tangents[i]! * step)}, ${n(point.x)} ${n(point.y)}`;
  }
  return path;
}

export function monthTicks(start: string, end: string, geometry = CHART): { x: number; label: string }[] {
  const first = parseDate(start);
  const last = parseDate(end) + DAY_MS;
  const date = new Date(first);
  let month = Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1);
  const ticks: { x: number; label: string }[] = [];
  while (month < last) {
    const current = new Date(month);
    const next = Date.UTC(current.getUTCFullYear(), current.getUTCMonth() + 1, 1);
    const midpoint = (Math.max(first, month) + Math.min(last, next)) / 2;
    ticks.push({
      x: geometry.left + ((midpoint - first) / (last - first)) * (geometry.right - geometry.left),
      label: new Intl.DateTimeFormat('en-US', { month: 'short', timeZone: 'UTC' }).format(current).toUpperCase(),
    });
    month = next;
  }
  return ticks;
}
