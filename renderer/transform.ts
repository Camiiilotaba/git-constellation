import type { ChartGeometry, ContributionDay, WeeklyPoint } from './types.js';
import { DAY_MS, parseDate } from './validation.js';

export const CHART: ChartGeometry = { left: 155, right: 1480, top: 310, bottom: 478 };

interface Bucket { start: number; end: number; count: number }

/** Consecutive days that share a key form one bucket; the key is the bucket's first possible day. */
function buckets(days: ContributionDay[], key: (timestamp: number) => number): Bucket[] {
  const groups: Bucket[] = [];
  let previousKey: number | undefined;
  for (const day of days) {
    const timestamp = parseDate(day.date);
    const current = key(timestamp);
    const last = groups[groups.length - 1];
    if (current !== previousKey || !last) {
      groups.push({ start: timestamp, end: timestamp, count: day.count });
      previousKey = current;
    } else { last.end = timestamp; last.count += day.count; }
  }
  return groups;
}

function place(groups: Bucket[], geometry: ChartGeometry): WeeklyPoint[] {
  if (groups.length === 0) return [];
  const start = groups[0]!.start;
  // Day intervals are [00:00, next 00:00), including the last day.
  const duration = groups[groups.length - 1]!.end + DAY_MS - start;
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
      // Star sizes use the raw bucket ratio; coordinates never use decoration.
      radius: group.count === 0 ? 1.8 : 2.1 + 5.4 * (group.count / peak) ** 1.15,
    };
  });
}

/** Monday UTC buckets; truncated edge weeks retain their actual time midpoint. */
export function weeklyPoints(days: ContributionDay[], geometry: ChartGeometry = CHART): WeeklyPoint[] {
  return place(buckets(days, timestamp => timestamp - ((new Date(timestamp).getUTCDay() + 6) % 7) * DAY_MS), geometry);
}

/**
 * UTC calendar-month buckets. The month that contains the last day is still in progress, so it is not
 * drawn (its days still count in every total) unless fewer than two months would remain.
 */
export function monthlyPoints(days: ContributionDay[], geometry: ChartGeometry = CHART): WeeklyPoint[] {
  const groups = buckets(days, timestamp => {
    const date = new Date(timestamp);
    return Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1);
  });
  return place(groups.length > 2 ? groups.slice(0, -1) : groups, geometry);
}

/** A year label at each January; with no January in range, the first month carries its year. */
export function yearTicks(points: Pick<WeeklyPoint, 'start' | 'x'>[]): { x: number; label: string }[] {
  const januaries = points.filter(point => point.start.slice(5, 7) === '01');
  return (januaries.length ? januaries : points.slice(0, 1)).map(point => ({ x: point.x, label: point.start.slice(0, 4) }));
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
