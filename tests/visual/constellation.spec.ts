import { test, expect, type Page } from '@playwright/test';
import { readFile, mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { exportImage, preparePage } from '../../renderer/export.js';
import { loadAssets, renderHtml } from '../../renderer/template.js';
import { CHART } from '../../renderer/transform.js';
import type { ConstellationData } from '../../renderer/types.js';
import { allTimeData, demoData, liveData, manualLanguages, recalculate } from '../ts/fixtures.js';

async function render(page: Page, data: ConstellationData): Promise<void> {
  await preparePage(page, renderHtml(data, await loadAssets()));
}

async function assertNoMetricOverflow(page: Page): Promise<void> {
  const values = await page.locator('.metric strong, .metric small').evaluateAll(nodes => nodes.map(node => ({ scroll: node.scrollWidth, client: node.clientWidth })));
  for (const value of values) expect(value.scroll).toBeLessThanOrEqual(value.client + 1);
  const captions = await page.locator('.metric span, .metric small').evaluateAll(nodes => nodes.map(node => {
    const box = node.getBoundingClientRect();
    const card = node.closest('.metric')!.getBoundingClientRect();
    return { top: box.top, bottom: box.bottom, right: box.right, cardTop: card.top, cardBottom: card.bottom, cardRight: card.right };
  }));
  for (const caption of captions) {
    expect(caption.top).toBeGreaterThan(caption.cardTop);
    expect(caption.bottom).toBeLessThan(caption.cardBottom);
    expect(caption.right).toBeLessThan(caption.cardRight);
  }
}

test('live mode renders without the demo badge', async ({ page }) => {
  await render(page, liveData());
  await expect(page.locator('main')).toHaveScreenshot('live.png');
  await expect(page.locator('.demo')).toHaveCount(0);
  await expect(page.locator('.tagline')).toHaveCount(0);
  await expect(page.locator('.privacy')).toHaveText('Nov 2025 – Oct 2026');
  await assertNoMetricOverflow(page);
});

test('demo matches the panoramic screenshot baseline entirely offline', async ({ page }) => {
  const requests: string[] = [];
  const errors: string[] = [];
  page.on('request', request => { if (/^https?:/.test(request.url())) requests.push(request.url()); });
  page.on('pageerror', error => errors.push(error.message));
  await render(page, demoData());
  await expect(page.locator('main')).toHaveScreenshot('demo.png');
  await expect(page.locator('.demo')).toHaveText('DEMO / SIMULATED DATA');
  await expect(page.locator('.privacy')).toHaveText('Nov 2025 – Oct 2026');
  await expect(page.locator('.weekly-star')).toHaveCount(53);
  await expect(page.locator('.decorative-mesh')).toHaveAttribute('aria-hidden', 'true');
  await expect(page.locator('.decorative-branch')).toHaveCount(3);
  await expect(page.locator('.decorative-mesh [data-count]')).toHaveCount(0);
  expect(requests).toEqual([]);
  expect(errors).toEqual([]);
  await assertNoMetricOverflow(page);
});

test('live mode with a manually selected language list', async ({ page }) => {
  await render(page, liveData({ languagesSource: 'manual', languages: manualLanguages() }));
  await expect(page.locator('.language-criterion')).toHaveCount(0);
  await expect(page.locator('.languages')).toHaveAttribute('aria-label', 'Languages selected by the developer');
  await expect(page.locator('.language-name')).toHaveText(['TypeScript', 'PHP', 'Rust']);
  await expect(page.locator('main')).toHaveScreenshot('live-manual-languages.png');
});

test('all-time live mode draws one star per complete month with year labels', async ({ page }) => {
  await render(page, allTimeData());
  await expect(page.locator('.privacy')).toHaveText('Since Oct 2023');
  await expect(page.locator('.monthly-star')).toHaveCount(37);
  await expect(page.locator('.month-labels text')).toHaveText(['2024', '2025', '2026']);
  await expect(page.locator('.demo')).toHaveCount(0);
  await assertNoMetricOverflow(page);
  await expect(page.locator('main')).toHaveScreenshot('all-time.png');
});

const readmeCases = [
  { mode: 'demo', width: 960, data: demoData, snapshot: 'readme-960.png' },
  { mode: 'demo', width: 830, data: demoData, snapshot: 'readme-830.png' },
  { mode: 'live', width: 830, data: liveData, snapshot: 'live-readme-830.png' },
  { mode: 'all-time', width: 830, data: allTimeData, snapshot: 'all-time-readme-830.png' },
] as const;

for (const { mode, width, data, snapshot } of readmeCases) test(`exported ${mode} PNG fits a ${width}px GitHub README width`, async ({ page }) => {
  // Embed the actual exported PNG in a fresh page, rather than reflowing HTML.
  const directory = resolve('build/visual-preview');
  await mkdir(directory, { recursive: true });
  const input = resolve(directory, `${mode}.json`);
  const output = resolve(directory, mode === 'demo' ? 'constellation.png' : `${mode}.png`);
  const { writeFile } = await import('node:fs/promises');
  await writeFile(input, JSON.stringify(data()));
  await exportImage({ input, output, html: false });
  const encoded = (await readFile(output)).toString('base64');
  const height = width * 840 / 1920;
  await page.setViewportSize({ width, height: Math.ceil(height) });
  await page.context().setOffline(true);
  await page.setContent(`<style>html,body{margin:0;background:#020a10}img{display:block;width:${width}px;height:auto}</style><img src="data:image/png;base64,${encoded}" alt="${mode} Git Constellation"/>`);
  await page.locator('img').evaluate(async node => { await (node as HTMLImageElement).decode(); });
  const bounds = await page.locator('img').boundingBox();
  expect(bounds!.width).toBe(width);
  expect(bounds!.height).toBeCloseTo(height, 1);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
  expect(overflow).toBe(false);
  await expect(page.locator('img')).toHaveScreenshot(snapshot);
  await page.locator('img').screenshot({ path: resolve(directory, snapshot) });
});

test('zero activity, unavailable metrics and empty language ranking', async ({ page }) => {
  const data = demoData();
  data.days.forEach(day => { day.count = 0; });
  recalculate(data);
  data.metrics.pullRequests = null;
  data.metrics.codeReviews = null;
  data.languages = [];
  await render(page, data);
  await expect(page.locator('.empty-chart')).toBeVisible();
  await expect(page.locator('.metric strong').nth(0)).toHaveText('—');
  await expect(page.locator('.metric strong').nth(1)).toHaveText('—');
  await expect(page.locator('.metric strong').nth(2)).toHaveText('0');
  expect(await page.locator('[data-series="weekly"]').getAttribute('d')).not.toMatch(/NaN|Infinity/);
  await expect(page.locator('main')).toHaveScreenshot('zero-activity.png');
});

test('long profile, escaped text and huge optional metrics fit', async ({ page }) => {
  const data = demoData();
  data.profile.name = 'Alexandra María Rodríguez de la Constellation & Associates';
  data.profile.role = 'Senior Backend Software Engineer and Open Source Contributor';
  data.profile.stack = 'PHP / TypeScript / Symfony / Distributed Systems';
  data.metrics.pullRequests = Number.MAX_SAFE_INTEGER;
  data.metrics.codeReviews = 999_999;
  data.languages[0]!.name = 'A deliberately very long language name <script>sentinel</script>';
  await render(page, data);
  await expect(page.locator('script')).toHaveCount(0);
  await expect(page.locator('h1')).toHaveAttribute('title', data.profile.name);
  const heading = await page.locator('h1').boundingBox();
  const brand = await page.locator('.brand').boundingBox();
  expect(heading!.x + heading!.width).toBeLessThan(brand!.x);
  const subtitle = await page.locator('.subtitle').boundingBox();
  const contributions = await page.locator('.contributions').boundingBox();
  expect(subtitle!.y + subtitle!.height).toBeLessThan(contributions!.y);
  await assertNoMetricOverflow(page);
  await expect(page.locator('main')).toHaveScreenshot('long-profile.png');
});

test('uniform high activity and early peaks stay below profile text', async ({ page }) => {
  const data = demoData();
  data.days.forEach(day => { day.count = 1000; });
  recalculate(data);
  await render(page, data);
  const privacy = await page.locator('.privacy').boundingBox();
  expect(privacy!.y + privacy!.height + 15).toBeLessThan(CHART.top);
  const points = await page.locator('.weekly-star>circle:last-child').evaluateAll(nodes => nodes.map(node => Number(node.getAttribute('cy'))));
  expect(Math.min(...points)).toBeGreaterThanOrEqual(CHART.top);
  const assertFlaresAvoidPrivacy = async (): Promise<void> => {
    const flares = await page.locator('.star-flare').all();
    for (const node of flares) {
      const flare = (await node.boundingBox())!;
      expect(flare.y).toBeGreaterThanOrEqual(privacy!.y + privacy!.height + 6);
    }
  };
  await assertFlaresAvoidPrivacy();
  await expect(page.locator('main')).toHaveScreenshot('uniform-high-activity.png');
  // A high first partial week exercises the formerly hidden collision case.
  data.days.forEach((day, index) => { day.count = index < 2 ? 1_000_000 : 0; });
  recalculate(data);
  await render(page, data);
  expect(await page.locator('.weekly-star').first().getAttribute('data-count')).toBe('2000000');
  await assertFlaresAvoidPrivacy();
  await expect(page.locator('main')).toHaveScreenshot('early-peak.png');
});

test('the exported animated SVG at rest is the plain picture', async ({ page }) => {
  const directory = resolve('build/visual-preview');
  await mkdir(directory, { recursive: true });
  const input = resolve(directory, 'animated.json');
  const { writeFile } = await import('node:fs/promises');
  await writeFile(input, JSON.stringify(demoData()));
  await exportImage({ input, output: resolve(directory, 'animated.png'), html: false });
  const svg = await readFile(resolve(directory, 'animated.svg'), 'utf8');
  const reducedMotion = '@media (prefers-reduced-motion:reduce)';
  expect(svg).toContain(reducedMotion);
  // Chromium does not pass an emulated reduced-motion preference into image documents, so the SVG's own rule is switched on.
  const still = svg.replace(reducedMotion, '@media all');
  const jpeg = /<image href="(data:image\/jpeg;base64,[^"]+)"/.exec(svg)![1]!;
  await page.context().setOffline(true);
  const show = async (source: string): Promise<Buffer> => {
    await page.setContent(`<style>html,body{margin:0;background:#020a10}img{display:block}</style><img src="${source}" alt=""/>`);
    await page.locator('img').evaluate(async node => { await (node as HTMLImageElement).decode(); });
    return page.locator('img').screenshot();
  };
  const base = await show(jpeg);
  expect((await show(`data:image/svg+xml;base64,${Buffer.from(still).toString('base64')}`)).equals(base)).toBe(true);
  await expect(page.locator('img')).toHaveScreenshot('animated-at-rest.png');
});
