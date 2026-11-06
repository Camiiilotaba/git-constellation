import { beforeAll, describe, expect, it } from 'vitest';
import { loadAssets, renderHtml, type RenderAssets } from '../../renderer/template.js';
import { demoData, shortData } from './fixtures.js';
import { smoothPath, weeklyPoints } from '../../renderer/transform.js';

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
});
