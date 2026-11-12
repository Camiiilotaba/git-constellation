import { readFile } from 'node:fs/promises';
import { basename, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { ConstellationData } from './types.js';
import { CHART, monthlyPoints, monthTicks, smoothPath, weeklyPoints, yearTicks } from './transform.js';
import { validateData } from './validation.js';
import { icon } from './icons.js';
import { decorativeBranches, flareIndices } from './ornaments.js';

export function escapeText(value: string): string {
  return value.replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]!);
}

export interface RenderAssets { background: string; font: string; stylesheet: string }

export async function loadAssets(): Promise<RenderAssets> {
  const parent = fileURLToPath(new URL('../', import.meta.url));
  const directory = resolve(parent, basename(parent) === 'build' ? '../assets' : 'assets');
  const [background, font, stylesheet] = await Promise.all([
    readFile(resolve(directory, 'space.svg')),
    readFile(resolve(directory, 'fonts/Manrope.ttf')),
    readFile(resolve(directory, 'constellation.css'), 'utf8'),
  ]);
  return {
    stylesheet,
    background: `data:image/svg+xml;base64,${background.toString('base64')}`,
    font: `data:font/ttf;base64,${font.toString('base64')}`,
  };
}

const number = (value: number): string => new Intl.NumberFormat('en-US').format(value);
const PRIVACY_SENTENCE = {
  included: 'Totals include private activity as anonymized counts.',
  excluded: 'Totals cover public activity only.',
  unknown: 'Totals cover activity visible to the token.',
} as const;
const TOKEN_VISIBLE_NOTE = 'Visible to the token; may exclude private activity';
const coordinate = (value: number): string => value.toFixed(2);

function constellation(data: ConstellationData): string {
  const monthly = data.period.granularity === 'month';
  const unit = monthly ? 'month' : 'week';
  const points = monthly ? monthlyPoints(data.days) : weeklyPoints(data.days);
  const path = smoothPath(points);
  const peak = Math.max(0, ...points.map(point => point.count));
  // Months are spaced wider apart than weeks, so flares can sit closer together.
  const flares = flareIndices(points, monthly ? 100 : 150);
  const branches = decorativeBranches(points);
  const ticks = monthly ? yearTicks(points) : monthTicks(data.period.start, data.period.end);
  return `<svg class="constellation" viewBox="0 0 1920 840" role="img" aria-label="${monthly ? 'Monthly' : 'Weekly'} contributions. Normalized square-root scale, from zero to ${peak} contributions per ${unit}. Faint dashed branches are decorative.">
    <defs>
      <linearGradient id="lineColor"><stop stop-color="${data.theme.cyan}"/><stop offset=".45" stop-color="${data.theme.cyan}"/><stop offset=".64" stop-color="${data.theme.green}"/><stop offset=".8" stop-color="${data.theme.cyan}"/><stop offset="1" stop-color="${data.theme.green}"/></linearGradient>
      <filter id="lineGlow" x="-10%" y="-50%" width="120%" height="200%"><feGaussianBlur stdDeviation="2.5"/></filter>
      <filter id="starGlow" x="-300%" y="-300%" width="700%" height="700%"><feGaussianBlur stdDeviation="6"/></filter>
    </defs>
    <g class="decorative-mesh" aria-hidden="true" fill="none" stroke="${data.theme.cyan}" stroke-width=".9" stroke-dasharray="2 5" opacity=".24">
      ${branches.map(branch => `<g class="decorative-branch"><polyline points="${branch.vertices.map(vertex => `${coordinate(vertex.x)},${coordinate(vertex.y)}`).join(' ')}"/>${branch.vertices.slice(1, -1).map(vertex => `<circle cx="${coordinate(vertex.x)}" cy="${coordinate(vertex.y)}" r="1.7" fill="${data.theme.cyan}" stroke="none"/>`).join('')}</g>`).join('')}
    </g>
    <path d="${path}" fill="none" stroke="url(#lineColor)" stroke-width="4" opacity=".14" filter="url(#lineGlow)" aria-hidden="true"/>
    <path data-series="${monthly ? 'monthly' : 'weekly'}" d="${path}" fill="none" stroke="url(#lineColor)" stroke-width="1.15" opacity="${peak === 0 ? '.2' : '.6'}"/>
    ${points.map((point, index) => {
      const strength = peak === 0 ? 0 : point.count / peak;
      const color = strength >= .72 ? data.theme.green : data.theme.cyan;
      const bright = flares.has(index);
      return `<g class="${monthly ? 'monthly' : 'weekly'}-star" data-count="${point.count}" data-${unit}="${point.start}" aria-label="${point.start} to ${point.end}: ${point.count} contributions">
        <title>${point.start} – ${point.end}: ${point.count} contributions</title>
        ${point.count > 0 ? `<circle cx="${coordinate(point.x)}" cy="${coordinate(point.y)}" r="${coordinate(point.radius * (bright ? 3 : 1.8))}" fill="${color}" opacity="${coordinate(.1 + strength * (bright ? .39 : .24))}" filter="url(#starGlow)"/>` : ''}
        ${bright ? `<g class="star-flare" aria-hidden="true"><path d="M${coordinate(point.x - 17)} ${coordinate(point.y)}h34M${coordinate(point.x)} ${coordinate(point.y - 17)}v34" stroke="${color}" stroke-width=".8" opacity=".55"/><path d="M${coordinate(point.x - 6)} ${coordinate(point.y - 6)}l12 12M${coordinate(point.x + 6)} ${coordinate(point.y - 6)}l-12 12" stroke="${color}" stroke-width=".6" opacity=".3"/></g>` : ''}
        <circle class="data-star" cx="${coordinate(point.x)}" cy="${coordinate(point.y)}" r="${coordinate(point.radius)}" fill="${point.count ? color : '#5b9aa4'}" opacity="${point.count ? coordinate(.55 + strength * .45) : '.35'}"/>
        ${bright ? `<circle cx="${coordinate(point.x)}" cy="${coordinate(point.y)}" r="${coordinate(point.radius * .48)}" fill="#e2fff4"/>` : ''}
      </g>`;
    }).join('')}
    <g class="month-labels" text-anchor="middle">
      ${ticks.map(tick => `<path d="M${coordinate(tick.x)} 493v10" stroke="#77c9cf" stroke-width="1" opacity=".8"/><text x="${coordinate(tick.x)}" y="526">${tick.label}</text>`).join('')}
    </g>
    ${peak === 0 ? `<text class="empty-chart" x="${(CHART.left + CHART.right) / 2}" y="440" text-anchor="middle">${data.mode === 'demo' ? 'No contributions in this example period' : 'No contributions in this period'}</text>` : ''}
  </svg>`;
}

function metric(label: string, value: number | null, name: string, title?: string): string {
  const formatted = value === null ? '—' : value >= 1_000_000 ? new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(value) : number(value);
  return `<section class="metric" aria-label="${label}"${title === undefined ? '' : ` title="${title}"`}>
    ${icon(name)}<div class="metric-copy"><strong data-fit data-min-size="24" title="${value === null ? 'Not available' : number(value)}">${formatted}</strong><span>${label}</span>${value === null ? '<small data-fit data-min-size="15">Not available</small>' : ''}</div>
  </section>`;
}

/** All user strings are escaped and all color attributes are validated first. */
export function renderHtml(input: ConstellationData, assets: RenderAssets): string {
  const data = validateData(input);
  const { profile, metrics } = data;
  const formatDate = (value: string): string => new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`));
  const period = `${formatDate(data.period.start)} – ${formatDate(data.period.end)}`;
  const formatMonth = (value: string): string => new Intl.DateTimeFormat('en-US', { month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`));
  const compactPeriod = data.period.kind === 'all-time'
    ? metrics.totalContributions === 0 ? 'No contributions yet' : `Since ${formatMonth(data.period.start)}`
    : formatMonth(data.period.start) === formatMonth(data.period.end) ? formatMonth(data.period.start) : `${formatMonth(data.period.start)} – ${formatMonth(data.period.end)}`;
  const demo = data.mode === 'demo';
  const activityTitle = demo ? 'Simulated' : TOKEN_VISIBLE_NOTE;
  const manual = data.languagesSource === 'manual';
  // In manual mode the list is chosen by each user via the `languages` config key and can be adjusted freely.
  const languages = data.languages;
  const languagesNote = manual ? 'Languages were selected by the developer.' : 'Language ranking counts eligible public repositories, including secondary languages.';
  return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:; base-uri 'none'; form-action 'none'"/>
<title>${escapeText(profile.name)} · Git Constellation${demo ? ' · Demo' : ''}</title>
<style>
@font-face{font-family:Manrope;src:url('${assets.font}') format('truetype');font-weight:200 800;font-style:normal;font-display:block}
:root{--cyan:${data.theme.cyan};--green:${data.theme.green};--white:#f3f7fa;--muted:#b1c6d0;color-scheme:dark}
${assets.stylesheet}
</style></head><body>
<main aria-label="${escapeText(profile.name)}: ${demo ? 'simulated ' : ''}GitHub contribution constellation">
<img class="background" alt="" src="${assets.background}"/>
<div class="orbit" aria-hidden="true"><svg viewBox="0 0 157 141"><defs><linearGradient id="orbitGradient"><stop stop-color="#1c7a7f" stop-opacity=".1"/><stop offset=".45" stop-color="${data.theme.green}"/><stop offset="1" stop-color="${data.theme.cyan}"/></linearGradient><filter id="orbitGlow"><feGaussianBlur stdDeviation="4"/></filter></defs><path d="M11 110C-9 136 30 143 82 120S153 53 145 30 104-7 72 13" fill="none" stroke="url(#orbitGradient)" stroke-width="1.4"/><path d="M11 110C-9 136 30 143 82 120S153 53 145 30 104-7 72 13" fill="none" stroke="url(#orbitGradient)" stroke-width="4" opacity=".4" filter="url(#orbitGlow)"/><path d="M28 71C43 31 58 11 85 10" fill="none" stroke="#137b85" stroke-width=".6" opacity=".5"/><circle cx="114" cy="13" r="2.5" fill="#bcffff"/><circle cx="84" cy="119" r="2.5" fill="${data.theme.green}"/></svg>${icon('code')}</div>
<header class="identity"><h1 data-fit data-min-size="38" title="${escapeText(profile.name)}">${escapeText(profile.name)}</h1><div class="subtitle" data-fit data-min-size="20" title="${escapeText(`${profile.role} · ${profile.stack}`)}"><span class="role">${escapeText(profile.role)}</span> · ${escapeText(profile.stack)}</div></header>
<div class="brand"><div class="brand-title">DEVELOPER CONSTELLATION</div>${demo ? '<div class="demo">DEMO / SIMULATED DATA</div>' : ''}</div>
<section class="contributions" aria-label="${demo ? 'Simulated contributions' : 'GitHub contributions'}"><div class="contribution-copy"><div class="contribution-total" data-fit data-min-size="32"><strong>${number(metrics.totalContributions)}</strong><span>contributions</span></div><div class="privacy" title="${period}">${icon('calendar')}<span>${compactPeriod}</span></div></div></section>
${constellation(data)}
<aside class="languages" aria-label="${manual ? 'Languages selected by the developer' : 'Languages ranked by public repository count'}"><h2>${icon('code')}Top Languages</h2>
${languages.length ? `<ol>${languages.map((language, index) => `<li title="${escapeText(manual ? language.name : `${language.name}: ${language.repositories} public repositories`)}"><span class="language-number">${index + 1}.</span><span class="language-dot" style="background:${language.color}"></span><span class="language-name">${escapeText(language.name)}</span></li>`).join('')}</ol>${manual ? '' : '<p class="language-criterion">By public repository count</p>'}` : `<p class="no-languages">No eligible public<br/>repositories${demo ? ' in this example' : ''}.</p>`}</aside>
<div class="metrics">
${metric('Pull Requests', metrics.pullRequests, 'branch', activityTitle)}
${metric('Code Reviews', metrics.codeReviews, 'review', activityTitle)}
${metric('Active Days', metrics.activeDays, 'calendar')}
${metric('Longest Streak', metrics.longestStreak, 'flame')}
</div>
<p class="sr-only">${demo
  ? `This is a demonstration with simulated data, not live GitHub statistics. Username: ${escapeText(profile.username)}. ${metrics.totalContributions} contributions; ${metrics.activeDays} active days. ${languagesNote}`
  : `Live GitHub statistics for ${escapeText(profile.username)}, generated ${escapeText(data.generatedAt)}. ${metrics.totalContributions} contributions; ${metrics.activeDays} active days. ${PRIVACY_SENTENCE[data.privateContributions]} Pull requests and code reviews count activity visible to the token and may exclude private activity. ${languagesNote}`}</p>
</main></body></html>`;
}
