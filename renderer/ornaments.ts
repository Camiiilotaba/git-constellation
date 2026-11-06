import type { ChartGeometry, WeeklyPoint } from './types.js';
import { CHART } from './transform.js';

interface Position { x: number; y: number }
export interface DecorativeBranch { vertices: Position[] }

/** Select isolated local maxima, treating a flat plateau as one event. */
export function flareIndices(points: readonly WeeklyPoint[]): Set<number> {
  const peak = Math.max(0, ...points.map(point => point.count));
  if (!peak) return new Set();
  const candidates: number[] = [];
  for (let first = 0; first < points.length; first++) {
    let last = first;
    while (last + 1 < points.length && points[last + 1]!.count === points[first]!.count) last++;
    const count = points[first]!.count;
    if (count >= peak * .55 && count > (points[first - 1]?.count ?? -1) && count > (points[last + 1]?.count ?? -1)) {
      candidates.push(Math.floor((first + last) / 2));
    }
    first = last;
  }
  const selected: number[] = [];
  candidates.sort((a, b) => points[b]!.count - points[a]!.count || a - b);
  for (const index of candidates) {
    if (selected.every(other => Math.abs(points[index]!.x - points[other]!.x) >= 150)) selected.push(index);
    if (selected.length === 5) break;
  }
  return new Set(selected);
}

/**
 * Three faint branches at most. These are ornaments, never weekly observations.
 * Anchor them to existing stars while keeping every invented vertex in bounds.
 * Fixed offsets are purely decorative; they never feed back into data geometry.
 */
export function decorativeBranches(points: readonly WeeklyPoint[], geometry: ChartGeometry = CHART): DecorativeBranch[] {
  if (points.length < 12 || !points.some(point => point.count > 0)) return [];
  return [.18, .48, .78].flatMap(fraction => {
    const index = Math.round((points.length - 4) * fraction);
    const first = points[index]!;
    const last = points[index + 3]!;
    if (first.count === 0 && last.count === 0) return [];
    const baseline = (first.y + last.y) / 2;
    const direction = geometry.bottom - baseline >= baseline - geometry.top ? 1 : -1;
    const clamp = (y: number): number => Math.max(geometry.top + 5, Math.min(geometry.bottom - 5, y));
    const width = last.x - first.x;
    return [{ vertices: [
      { x: first.x, y: first.y },
      { x: first.x + width * .3, y: clamp(baseline + direction * 35) },
      { x: first.x + width * .7, y: clamp(baseline + direction * 55) },
      { x: last.x, y: last.y },
    ] }];
  });
}
