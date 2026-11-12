import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { FIGURE_BOX, PLANET_RIM, renderAnimatedSvg, SCREEN_GLOW, skyStars, type AnimationScene } from '../../renderer/animation.js';
import { flareIndices } from '../../renderer/ornaments.js';
import { monthlyPoints, smoothPath, weeklyPoints } from '../../renderer/transform.js';
import type { WeeklyPoint } from '../../renderer/types.js';
import { allTimeData, demoData } from './fixtures.js';

const background = readFileSync(new URL('../../assets/space.svg', import.meta.url), 'utf8');
const sky = skyStars(background);
const jpeg = Buffer.from('not really a jpeg');
// Boxes the exporter reads from the demo page.
const blocked = [
  { x: 48, y: 31, w: 157, h: 141 }, { x: 235, y: 35, w: 875, h: 132 }, { x: 1216, y: 54, w: 655, h: 119 },
  { x: 235, y: 169, w: 474, h: 115 }, { x: 1522, y: 186, w: 358, h: 346 }, { x: 352, y: 558, w: 1398, h: 126 },
  ...[179, 291, 406, 514, 615, 729, 838, 951, 1064, 1172, 1286, 1393].map(x => ({ x, y: 496, w: 60, h: 38 })),
];

function scene(points: WeeklyPoint[], label = 'Alex Morgan: simulated GitHub contribution constellation'): AnimationScene {
  const flares = flareIndices(points);
  return {
    line: points.length ? smoothPath(points) : '',
    stars: points.map((point, index) => ({ x: Number(point.x.toFixed(2)), y: Number(point.y.toFixed(2)), r: Number(point.radius.toFixed(2)), fill: point.count ? '#30e8ef' : '#5b9aa4', count: point.count, bright: flares.has(index) })),
    blocked,
    orbit: { x: 48, y: 31 },
    label,
  };
}
const weekly = (): AnimationScene => scene(weeklyPoints(demoData().days));
const onGrid = (value: number): boolean => Math.abs(value - Math.round(value)) < 1e-6;

describe('animated SVG', () => {
  const svg = renderAnimatedSvg(weekly(), sky, jpeg);

  it('is self-contained: no scripts, filters, foreign objects or external references', () => {
    expect(svg).toMatch(/^<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg" width="1920" height="840" viewBox="0 0 1920 840" role="img" aria-label="/);
    expect(svg).not.toMatch(/<script|<filter|<foreignObject|xlink|@import/i);
    const references = [...svg.matchAll(/href="([^"]*)"/g)].map(match => match[1]);
    expect(references).toEqual([`data:image/jpeg;base64,${jpeg.toString('base64')}`]);
    expect([...svg.matchAll(/url\(([^)]*)\)/g)].every(match => match[1]!.startsWith('#'))).toBe(true);
    expect(svg).toContain('@media (prefers-reduced-motion:reduce){*{animation:none!important}}');
    expect(svg.match(/<style>/g)).toHaveLength(1);
  });

  it('runs every animation on one 30 fps clock', () => {
    const durations = new Map([...svg.matchAll(/\.(\w+)\{[^}]*animation:(\w+) ([\d.]+)s linear (-?[\d.]+)s infinite\}/g)].map(match => {
      expect(match[1]).toBe(match[2]);
      expect(onGrid(Number(match[3]) * 30)).toBe(true);
      expect(Number(match[4])).toBeCloseTo(-1 / 240, 8);
      return [match[1]!, Math.round(Number(match[3]) * 30)];
    }));
    const keyframes = [...svg.matchAll(/@keyframes (\w+)\{((?:[\d.]+%\{[^}]*\})+)\}/g)];
    expect(keyframes.length).toBe(durations.size);
    for (const [, name, body] of keyframes) {
      const total = durations.get(name!)!;
      const points = [...body!.matchAll(/([\d.]+)%\{([^}]*)\}/g)].map(match => {
        const frame = Number(match[1]) / 100 * total;
        expect(onGrid(frame), `${name} ${match[1]}%`).toBe(true);
        const [css, steps] = match[2]!.split(';animation-timing-function:');
        return { frame: Math.round(frame), css, steps };
      });
      expect(points[0]!.frame).toBe(0);
      expect(points.at(-1)!.frame).toBe(total);
      points.forEach((point, index) => {
        const next = points[index + 1];
        if (!next) return expect(point.steps).toBeUndefined();
        if (point.steps === undefined) return expect(point.css).toBe(next.css);
        expect(point.steps).toBe(`steps(${next.frame - point.frame})`);
      });
    }
    const delays = [...svg.matchAll(/<(\w+) class="(\w+)" [^>]*?animation-delay:(-?[\d.]+)s/g)];
    expect(delays.length).toBeGreaterThan(12);
    for (const [, , name, value] of delays) {
      const delay = Number(value);
      if (name === 'ripple') expect(onGrid((delay + 1 / 240) * 30) && delay + 1 / 240 >= 0).toBe(true);
      else expect(onGrid(-delay * 30 - 1 / 8) && delay < 0).toBe(true);
    }
  });

  it('draws twelve twinkle paths in four bands, clear of text, cards and data stars', () => {
    const input = weekly();
    const paths = [...svg.matchAll(/<path class="\w+" style="[^"]*" fill="#f1fffd" d="([^"]+)"/g)].map(match =>
      [...match[1]!.matchAll(/M([\d.]+) [\d.-]+L(?:[\d.-]+ ){3}([\d.]+)/g)].map(sparkle => ({ x: Number(sparkle[1]), y: Number(sparkle[2]) })));
    expect(paths).toHaveLength(12);
    expect(paths.flat()).toHaveLength(108);
    const bands = [0, 1, 2, 3].map(column => paths.slice(column * 3, column * 3 + 3).flat().map(point => point.x));
    for (let column = 1; column < 4; column++) expect(Math.min(...bands[column]!)).toBeGreaterThanOrEqual(Math.max(...bands[column - 1]!));
    for (const { x, y } of paths.flat()) {
      for (const box of [...input.blocked, FIGURE_BOX]) expect(x > box.x - 14 && x < box.x + box.w + 14 && y > box.y - 14 && y < box.y + box.h + 14).toBe(false);
      for (const star of input.stars) expect(Math.hypot(star.x - x, star.y - y)).toBeGreaterThanOrEqual(22);
    }
  });

  it('keeps every animated element invisible at rest', () => {
    const rest = new Map([...svg.matchAll(/\.(\w+)\{([^}]*?)animation:/g)].map(match => [match[1]!, match[2]!]));
    for (const [name, css] of rest) expect(['opacity:0;', 'stroke-dashoffset:-150;'], name).toContain(css);
    const used = new Set([...svg.matchAll(/class="(\w+)"/g)].map(match => match[1]!));
    for (const name of used) expect(rest.has(name), name).toBe(true);
    // A parked dash must lie outside the 0..100 path length, including the next repeat of the pattern.
    for (const [, dash] of svg.matchAll(/stroke-dasharray="([\d.]+) 300"/g)) {
      const period = Number(dash) + 300;
      const start = ((150 % period) + period) % period;
      expect(start > 100 && start + Number(dash) - period < 0).toBe(true);
    }
  });

  it('is deterministic and escapes the label', () => {
    expect(renderAnimatedSvg(weekly(), sky, jpeg)).toBe(svg);
    const escaped = renderAnimatedSvg({ ...weekly(), label: 'A & B <C> "D"' }, sky, jpeg);
    expect(escaped).toContain('aria-label="A &amp; B &lt;C&gt; &quot;D&quot;"');
    expect(escaped).toContain('<title>A &amp; B &lt;C&gt; &quot;D&quot;</title>');
  });

  const zero = demoData();
  zero.days.forEach(day => { day.count = 0; });
  const single = weeklyPoints(demoData().days).slice(0, 1);
  it.each([
    ['no data stars', scene([]), sky],
    ['a single data star', scene(single), sky],
    ['zero activity', scene(weeklyPoints(zero.days)), sky],
    ['fewer free sky stars than groups', weekly(), sky.filter(star => star.r >= .5 && star.y < 100).slice(0, 5)],
    ['no sky at all', weekly(), []],
    ['monthly all-time', scene(monthlyPoints(allTimeData().days)), sky],
  ])('stays valid with %s', (_, input, stars) => {
    const output = renderAnimatedSvg(input, stars, jpeg);
    expect(output).not.toMatch(/NaN|Infinity|undefined|d=""|steps\(0\)/);
    if (input.stars.every(star => star.count === 0)) expect(output).not.toMatch(/class="(ripple|pulse)"/);
  });

  it('counts the monthly view as 37 stars and the weekly view as 53', () => {
    expect(scene(monthlyPoints(allTimeData().days)).stars).toHaveLength(37);
    expect(weekly().stars).toHaveLength(53);
  });

  it('follows the shapes drawn in the background', () => {
    const screen = 'M241.1 649.1 262.9 660.1 270.9 628.9 249.1 617.9Z';
    expect(background).toContain(`<path d="${screen}"`);
    const corners = [...screen.matchAll(/([\d.]+) ([\d.]+)/g)].map(match => [Number(match[1]), Number(match[2])] as const);
    expect(corners.reduce((sum, [x]) => sum + x, 0) / 4).toBeCloseTo(SCREEN_GLOW.cx, 1);
    expect(corners.reduce((sum, [, y]) => sum + y, 0) / 4).toBeCloseTo(SCREEN_GLOW.cy, 1);
    for (const [x, y] of corners) expect(x > FIGURE_BOX.x && x < FIGURE_BOX.x + FIGURE_BOX.w && y > FIGURE_BOX.y && y < FIGURE_BOX.y + FIGURE_BOX.h).toBe(true);
    expect(background).toContain('<ellipse cx="1000" cy="1235" rx="810" ry="530"');
    const [, x1, y1, rx, ry, x2, y2] = /^M([\d.]+) ([\d.]+)A([\d.]+) ([\d.]+) 0 0 1 ([\d.]+) ([\d.]+)$/.exec(PLANET_RIM)!.map(Number);
    expect([rx, ry]).toEqual([810, 530]);
    for (const [x, y] of [[x1!, y1!], [x2!, y2!]]) expect(Math.abs(((x - 1000) / 810) ** 2 + ((y - 1235) / 530) ** 2 - 1)).toBeLessThan(.01);
  });

  it('reads only the top-level sky circles of the background', () => {
    expect(sky.length).toBeGreaterThan(1000);
    expect(sky.every(star => Number.isFinite(star.x + star.y + star.r))).toBe(true);
    expect(skyStars('<svg>\n  <circle cx="1" cy="2" r="3"/>\n<circle cx="4" cy="5" r="6"/>\n</svg>')).toEqual([{ x: 4, y: 5, r: 6 }]);
  });
});
