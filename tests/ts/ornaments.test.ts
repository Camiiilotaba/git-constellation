import { describe, expect, it } from 'vitest';
import { decorativeBranches, flareIndices } from '../../renderer/ornaments.js';
import { CHART, smoothPath, weeklyPoints } from '../../renderer/transform.js';
import { demoData } from './fixtures.js';

describe('ornaments remain separate from measured contributions', () => {
  it('never modifies weekly counts, positions, radii or the continuous data path', () => {
    const data = demoData();
    const originalDays = structuredClone(data.days);
    const points = weeklyPoints(data.days);
    const originalPoints = structuredClone(points);
    const path = smoothPath(points);
    const mesh = decorativeBranches(points);
    const flares = flareIndices(points);
    expect(mesh).toEqual(decorativeBranches(points));
    expect(flares).toEqual(flareIndices(points));
    expect(points).toEqual(originalPoints);
    expect(data.days).toEqual(originalDays);
    expect(smoothPath(points)).toBe(path);
    expect(points.reduce((sum, point) => sum + point.count, 0)).toBe(data.metrics.totalContributions);
    expect(mesh).toHaveLength(3);
    for (const branch of mesh) {
      expect(branch.vertices).toHaveLength(4);
      expect(points.some(point => point.x === branch.vertices[0]!.x && point.y === branch.vertices[0]!.y)).toBe(true);
      for (const vertex of branch.vertices) {
        expect(Object.keys(vertex).sort()).toEqual(['x', 'y']);
        expect(vertex.x).toBeGreaterThanOrEqual(CHART.left);
        expect(vertex.x).toBeLessThanOrEqual(CHART.right);
        expect(vertex.y).toBeGreaterThanOrEqual(CHART.top);
        expect(vertex.y).toBeLessThanOrEqual(CHART.bottom);
      }
    }
    expect(flares.size).toBeGreaterThan(0);
    expect(flares.size).toBeLessThanOrEqual(5);
    const indices = [...flares];
    for (const [position, index] of indices.entries()) {
      for (const other of indices.slice(position + 1)) expect(Math.abs(points[index]!.x - points[other]!.x)).toBeGreaterThanOrEqual(150);
    }
  });

  it('keeps decoration quiet with no activity and selects one flare per plateau', () => {
    const data = demoData();
    data.days.forEach(day => { day.count = 0; });
    expect(decorativeBranches(weeklyPoints(data.days))).toEqual([]);
    expect(flareIndices(weeklyPoints(data.days)).size).toBe(0);
    expect(decorativeBranches([])).toEqual([]);
    expect(flareIndices([]).size).toBe(0);
    const points = weeklyPoints(data.days).map(point => ({ ...point, count: 7 }));
    expect([...flareIndices(points)]).toEqual([Math.floor((points.length - 1) / 2)]);
  });

  it('keeps every ornamental vertex inside bounds for high weeks and an early peak', () => {
    const data = demoData();
    for (const counts of [data.days.map(() => 1000), data.days.map((_, index) => index < 2 ? 1_000_000 : 0)]) {
      const points = weeklyPoints(data.days.map((day, index) => ({ ...day, count: counts[index]! })));
      for (const { vertices } of decorativeBranches(points)) {
        for (const { x, y } of vertices) {
          expect(x).toBeGreaterThanOrEqual(CHART.left);
          expect(x).toBeLessThanOrEqual(CHART.right);
          expect(y).toBeGreaterThanOrEqual(CHART.top);
          expect(y).toBeLessThanOrEqual(CHART.bottom);
        }
      }
      expect(flareIndices(points).size).toBe(1);
    }
  });
});
