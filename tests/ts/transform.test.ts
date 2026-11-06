import { describe, expect, it } from 'vitest';
import { CHART, monthTicks, smoothPath, weeklyPoints } from '../../renderer/transform.js';
import { shortData } from './fixtures.js';

describe('weekly contribution coordinates', () => {
  it('uses UTC Monday weeks including both partial edge weeks', () => {
    const days = shortData([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]).days;
    const points = weeklyPoints(days, { left: 0, right: 100, top: 0, bottom: 100 });
    expect(points.map(p => [p.start, p.end, p.count])).toEqual([
      ['2025-11-01', '2025-11-02', 3],
      ['2025-11-03', '2025-11-09', 42],
      ['2025-11-10', '2025-11-10', 10],
    ]);
    for (const [index, expected] of [10, 55, 95].entries()) expect(points[index]!.x).toBeCloseTo(expected);
    expect(points[0]!.midpoint).toBe(Date.parse('2025-11-02T00:00:00Z'));
    expect(points[2]!.midpoint).toBe(Date.parse('2025-11-10T12:00:00Z'));
    expect(points.reduce((sum, p) => sum + p.count, 0)).toBe(55);
    expect(points[1]!.y).toBe(0);
    expect(points[0]!.y).toBeCloseTo(100 - Math.sqrt(3 / 42) * 100);
    expect(points[0]!.radius).toBeLessThan(points[2]!.radius);
    expect(points[2]!.radius).toBeLessThan(points[1]!.radius);
  });

  it('does not move points or alter values between repeated renders', () => {
    const data = shortData([9, 0, 2, 11, 0, 0, 1]);
    const before = structuredClone(data.days);
    expect(weeklyPoints(data.days)).toEqual(weeklyPoints(data.days));
    expect(data.days).toEqual(before);
  });

  it('uses the same height for equal measured activity regardless of time', () => {
    const days = Array.from({ length: 28 }, (_, index) => ({
      date: new Date(Date.UTC(2025, 10, 3 + index)).toISOString().slice(0, 10),
      count: [4, 16, 4, 0][Math.floor(index / 7)]!,
    }));
    const points = weeklyPoints(days);
    expect(points.map(point => point.intensity)).toEqual([.5, 1, .5, 0]);
    expect(points[0]!.y).toBe(points[2]!.y);
    expect(points[3]!.y).toBe(CHART.bottom);
    expect(points[1]!.y).toBe(CHART.top);
    // Uniformly changing counts preserves relative geometry, not absolute totals.
    const scaled = weeklyPoints(days.map(day => ({ ...day, count: day.count * 4 })));
    expect(scaled.map(({ x, y, radius }) => ({ x, y, radius }))).toEqual(points.map(({ x, y, radius }) => ({ x, y, radius })));
    expect(scaled.reduce((sum, point) => sum + point.count, 0)).toBe(4 * points.reduce((sum, point) => sum + point.count, 0));
  });

  it('keeps a quiet baseline and small dim stars for zero activity', () => {
    const points = weeklyPoints(shortData(Array<number>(14).fill(0)).days);
    expect(points.every(p => p.y === CHART.bottom && p.radius === 1.8 && p.intensity === 0)).toBe(true);
    expect(smoothPath(points)).not.toMatch(/NaN|Infinity/);
    expect(weeklyPoints([])).toEqual([]);
    expect(smoothPath([])).toBe('');
  });

  it('keeps extreme activity inside the same chart bounds', () => {
    const points = weeklyPoints(shortData([0, 1, 2, 1_000_000_000, 0, 1, 99, 0, 2]).days);
    expect(points.every(p => p.x >= CHART.left && p.x <= CHART.right && p.y >= CHART.top && p.y <= CHART.bottom)).toBe(true);
    expect(Math.min(...points.map(p => p.y))).toBe(CHART.top);
  });

  it('handles leap day and year boundaries in UTC', () => {
    expect(weeklyPoints([{ date: '2024-02-28', count: 1 }, { date: '2024-02-29', count: 2 }, { date: '2024-03-01', count: 3 }])[0]!.count).toBe(6);
    expect(weeklyPoints([{ date: '2025-12-31', count: 2 }, { date: '2026-01-01', count: 3 }])).toHaveLength(1);
  });
});

describe('smooth curve and month axis', () => {
  it('creates one measured node for a single bucket', () => {
    expect(smoothPath([{ x: 10, y: 20 }])).toBe('M 10.00 20.00');
  });

  it('interpolates smoothly without overshooting any neighboring values', () => {
    const points = [{ x: 0, y: 100 }, { x: 5, y: 10 }, { x: 50, y: 85 }, { x: 80, y: 20 }, { x: 100, y: 45 }];
    const values = smoothPath(points).match(/-?\d+(?:\.\d+)?/g)!.map(Number).slice(2);
    for (let i = 1; i < points.length; i++) {
      const start = points[i - 1]!;
      const end = points[i]!;
      const controls = values.slice((i - 1) * 6, i * 6);
      for (let sample = 0; sample <= 20; sample++) {
        const t = sample / 20;
        const y = (1 - t) ** 3 * start.y + 3 * (1 - t) ** 2 * t * controls[1]! + 3 * (1 - t) * t ** 2 * controls[3]! + t ** 3 * end.y;
        expect(y).toBeGreaterThanOrEqual(Math.min(start.y, end.y) - .01);
        expect(y).toBeLessThanOrEqual(Math.max(start.y, end.y) + .01);
      }
    }
  });

  it('places twelve labels at true month time midpoints', () => {
    const ticks = monthTicks('2025-11-01', '2026-10-31');
    expect(ticks.map(tick => tick.label)).toEqual(['NOV', 'DEC', 'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT']);
    expect(ticks[0]!.x).toBeCloseTo(CHART.left + 15 / 365 * (CHART.right - CHART.left));
    expect(monthTicks('2026-03-20', '2026-03-20')[0]!.x).toBe((CHART.left + CHART.right) / 2);
  });
});
