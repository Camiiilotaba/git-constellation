import { beforeAll, describe, expect, it } from 'vitest';
import { loadAssets, renderHtml, type RenderAssets } from '../../renderer/template.js';
import { demoData, liveData, recalculate, shortData } from './fixtures.js';
import { smoothPath, weeklyPoints } from '../../renderer/transform.js';

const LOCK_PATH = 'M15 19v-7a7 7 0 0 1 14 0v7';
let assets: RenderAssets;
beforeAll(async () => { assets = await loadAssets(); });

describe('HTML composition', () => {
  it('escapes profile and language text and uses no external resources', () => {
    const data = shortData();
    data.profile.name = '<script>SENTINEL</script> & "a"';
    data.profile.role = '<img src="https://example.com/tracker">';
    data.languages[0]!.name = '<b>PHP</b>';
    const html = renderHtml(data, assets);
    expect(html).not.toContain('<script>SENTINEL');
    expect(html).toContain('&lt;script&gt;SENTINEL&lt;/script&gt; &amp; &quot;a&quot;');
    expect(html).toContain('&lt;b&gt;PHP&lt;/b&gt;');
    expect(html).not.toContain('src="https:');
    expect(html).toContain("default-src 'none'");
    expect(html).toContain('data:image/svg+xml;base64,');
    expect(html).toContain('data:font/ttf;base64,');
    expect(html).toContain('DEMO / SIMULATED DATA');
    expect(html).toContain('public activity only');
    expect(html).not.toContain('private counts anonymized');
  });

  it('distinguishes unavailable metrics from zero and handles empty languages', () => {
    const data = shortData([0, 0]);
    data.metrics.pullRequests = null; data.metrics.codeReviews = 0; data.languages = [];
    const html = renderHtml(data, assets);
    expect(html).toContain('Not available');
    expect(html).toContain('>—</strong>');
    expect(html).toContain('title="0">0</strong>');
    expect(html).toContain('No eligible public');
    expect(html).toContain('No contributions in this example period');
  });

  it('orders public repository counts with a deterministic name tie-break', () => {
    const data = shortData();
    data.languages = [
      { name: 'Zeta', repositories: 2, color: '#abcdef' },
      { name: 'Beta', repositories: 3, color: '#abcdef' },
      { name: 'Alpha', repositories: 2, color: '#abcdef' },
    ];
    const html = renderHtml(data, assets);
    expect(html.indexOf('>Beta</span>')).toBeLessThan(html.indexOf('>Alpha</span>'));
    expect(html.indexOf('>Alpha</span>')).toBeLessThan(html.indexOf('>Zeta</span>'));
    expect(data.languages[0]!.name).toBe('Zeta');
  });

  it('clearly distinguishes decorative branches from the measured data series', () => {
    const data = demoData();
    const html = renderHtml(data, assets);
    const mesh = html.match(/<g class="decorative-mesh"[\s\S]*?<\/g>\s*<path d=/)![0];
    expect(mesh).toContain('aria-hidden="true"');
    expect(mesh).toContain('stroke-dasharray="2 5"');
    expect(mesh).toContain('opacity=".24"');
    expect(mesh).not.toMatch(/data-count|data-week|data-series|aria-label|<title/);
    const points = weeklyPoints(data.days);
    expect(html).toContain(`data-series="weekly" d="${smoothPath(points)}"`);
    expect(html.match(/class="weekly-star"/g)).toHaveLength(points.length);
    expect(html.match(/class="data-star"/g)).toHaveLength(points.length);
    expect(html).toContain('Normalized square-root scale');
    expect(html).toContain('Faint dashed branches are decorative.');
  });

  it('omits the demo badge and the tagline in live mode', () => {
    const html = renderHtml(liveData(), assets);
    expect(html).not.toContain('DEMO / SIMULATED DATA');
    expect(html).not.toContain('ACTIVITY <b>•</b> CODE');
    expect(html).toContain('· Git Constellation</title>');
  });

  it('keeps the demo badge in demo mode but drops the tagline', () => {
    const html = renderHtml(demoData(), assets);
    expect(html).toContain('DEMO / SIMULATED DATA');
    expect(html).not.toContain('class="tagline"');
  });

  it('renders one subtitle per privateContributions state', () => {
    expect(renderHtml(liveData({ privateContributions: 'included' }), assets)).toContain('private counts anonymized');
    expect(renderHtml(liveData({ privateContributions: 'excluded' }), assets)).toContain('public activity only');
    expect(renderHtml(liveData({ privateContributions: 'unknown' }), assets)).toContain('visible activity only');
  });

  it('removes card secondary descriptions and keeps Not available for null', () => {
    const html = renderHtml(demoData(), assets);
    for (const text of ['Created · simulated', 'Submitted · simulated', 'In the example period', 'Consecutive active days']) {
      expect(html).not.toContain(text);
    }
    const nulls = renderHtml(liveData({ metrics: { ...liveData().metrics, pullRequests: null } }), assets);
    expect(nulls).toContain('<small data-fit data-min-size="15">Not available</small>');
  });

  it('labels PR and review cards as token-visible in live mode', () => {
    expect(renderHtml(liveData(), assets)).toContain('title="Visible to the token; may exclude private activity"');
  });

  it('uses live copy for labels, empty states and the screen-reader paragraph', () => {
    const data = liveData({ languages: [], privateContributions: 'excluded' });
    data.days.forEach(day => { day.count = 0; });
    recalculate(data);
    const html = renderHtml(data, assets);
    expect(html).toContain('aria-label="GitHub contributions"');
    expect(html).toContain('GitHub contribution constellation"');
    expect(html).not.toContain('simulated');
    expect(html).toContain('No contributions in this period');
    expect(html).toContain('No eligible public<br/>repositories.</p>');
    expect(html).toContain(`Live GitHub statistics for ${data.profile.username}, generated ${data.generatedAt}. 0 contributions; 0 active days.`);
    expect(html).not.toContain(LOCK_PATH);
    expect(renderHtml(liveData(), assets)).toContain(LOCK_PATH);
  });
});
