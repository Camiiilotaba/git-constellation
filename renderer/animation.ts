import { escapeText, ORBIT_PATH } from './template.js';

export interface AnimationScene {
  line: string;
  stars: { x: number; y: number; r: number; fill: string; count: number; bright: boolean }[];
  blocked: { x: number; y: number; w: number; h: number }[];
  orbit: { x: number; y: number };
  label: string;
}
type Sky = { x: number; y: number; r: number }[];
type Keyframes = [seconds: number, css: string][];

const FRAMES_PER_SECOND = 30;
// Step boundaries sit a quarter of a 60 Hz frame away from the display's sampling instants.
const FRAME_OFFSET = 1 / 240;
const SEED = 20261112;
const TWINKLE_COUNT = 108;
const TWINKLE_GROUPS = 12;
const TWINKLE_COLUMNS = 4;
// With stroke-dasharray="L 300" on pathLength="100" this parks the dash past the path end; 300 would leave it visible at L..2L.
const PARKED_DASH = 'stroke-dashoffset:-150;';
// These follow shapes drawn in assets/space.svg: the laptop screen, the planet rim and the seated figure.
export const SCREEN_GLOW = { cx: 256, cy: 639, rx: 34, ry: 38 };
export const PLANET_RIM = 'M455 842A810 530 0 0 1 1545 842';
export const FIGURE_BOX = { x: 130, y: 510, w: 190, h: 260 };

const n = (value: number): number => Number(value.toFixed(2));
/** Shortest decimal that stays on the frame grid; two decimals are not enough for thirtieths. */
const decimal = (value: number): string => {
  let digits = 2;
  while (digits < 8 && Math.abs(Number(value.toFixed(digits)) - value) > 1e-9) digits++;
  return String(Number(value.toFixed(digits)));
};
const frames = (seconds: number): number => Math.round(seconds * FRAMES_PER_SECOND);
const seconds = (value: number): number => frames(value) / FRAMES_PER_SECOND;
const delay = (value: number): string => `animation-delay:${decimal(-(seconds(value) + FRAME_OFFSET))}s`;

export function skyStars(backgroundSvg: string): Sky {
  return [...backgroundSvg.matchAll(/^<circle cx="([\d.]+)" cy="([\d.]+)" r="([\d.]+)"/gm)].map(match => ({ x: +match[1]!, y: +match[2]!, r: +match[3]! }));
}

export function renderAnimatedSvg(scene: AnimationScene, sky: Sky, baseJpeg: Buffer): string {
  let seed = SEED;
  const random = (): number => {
    seed |= 0; seed = seed + 0x6D2B79F5 | 0;
    let t = Math.imul(seed ^ seed >>> 15, 1 | seed);
    t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
    return ((t ^ t >>> 14) >>> 0) / 4294967296;
  };
  const rules: string[] = [];
  const classes: string[] = [];
  const define = (name: string, points: Keyframes, rest: string): string => {
    const total = frames(points.at(-1)![0]);
    const body = points.map(([at, css], index) => {
      const next = points[index + 1];
      const timing = !next || next[1] === css ? '' : `;animation-timing-function:steps(${frames(next[0]) - frames(at)})`;
      return `${decimal(frames(at) / total * 100)}%{${css}${timing}}`;
    }).join('');
    rules.push(`@keyframes ${name}{${body}}`);
    classes.push(`.${name}{${rest}animation:${name} ${decimal(total / FRAMES_PER_SECOND)}s linear ${decimal(-FRAME_OFFSET)}s infinite}`);
    return name;
  };

  const twinkle = (duration: number): string => define(`t${duration * 10}`, [[0, 'opacity:0'], [duration * .22, 'opacity:.1'], [duration * .5, 'opacity:1'], [duration * .78, 'opacity:.1'], [duration, 'opacity:0']], 'opacity:0;');
  const blink = (duration: number): string => define(`b${duration * 10}`, [[0, 'opacity:0'], [duration * .62, 'opacity:0'], [duration * .78, 'opacity:1'], [duration * .86, 'opacity:.35'], [duration * .92, 'opacity:.9'], [duration, 'opacity:0']], 'opacity:0;');
  const rhythms = [
    ...[2.5, 3, 3.5, 4, 4.5, 5, 5.5, 6, 6.5].map(duration => ({ duration, name: twinkle(duration) })),
    ...[4, 5.5, 7].map(duration => ({ duration, name: blink(duration) })),
  ];

  const blocked = [...scene.blocked, FIGURE_BOX];
  const free = (x: number, y: number): boolean => !blocked.some(box => x > box.x - 14 && x < box.x + box.w + 14 && y > box.y - 14 && y < box.y + box.h + 14)
    && !scene.stars.some(star => Math.hypot(star.x - x, star.y - y) < 22);
  const candidates = sky.filter(star => star.r >= .5 && star.y < 610 && free(star.x, star.y));
  for (let i = candidates.length - 1; i > 0; i--) {
    const j = Math.floor(random() * (i + 1));
    [candidates[i], candidates[j]] = [candidates[j]!, candidates[i]!];
  }
  const byColumn = candidates.slice(0, TWINKLE_COUNT).sort((a, b) => a.x - b.x);
  const perColumn = TWINKLE_GROUPS / TWINKLE_COLUMNS;
  const sparkle = (x: number, y: number, s: number, w: number): string => `M${x} ${n(y - s)}L${n(x + w)} ${n(y - w)} ${n(x + s)} ${y} ${n(x + w)} ${n(y + w)} ${x} ${n(y + s)} ${n(x - w)} ${n(y + w)} ${n(x - s)} ${y} ${n(x - w)} ${n(y - w)}Z`;
  const dot = (x: number, y: number, r: number): string => `M${n(x - r)} ${y}a${n(r)} ${n(r)} 0 1 0 ${n(r * 2)} 0a${n(r)} ${n(r)} 0 1 0 ${n(-r * 2)} 0`;
  // One path per rhythm, each inside one vertical band of the sky: browsers pay per animated element, and tile rasterizers replay a path in every tile its bounds touch.
  const twinkles = Array.from({ length: TWINKLE_GROUPS }, (_, group) => {
    const stars = byColumn.filter((_, index) => Math.min(TWINKLE_COLUMNS - 1, Math.floor(index / (byColumn.length / TWINKLE_COLUMNS))) === Math.floor(group / perColumn) && index % perColumn === group % perColumn);
    const { name, duration } = rhythms[(group * 5) % rhythms.length]!;
    const style = delay(random() * duration);
    return stars.length ? `<path class="${name}" style="${style}" fill="#f1fffd" d="${stars.map(star => sparkle(star.x, star.y, n(star.r * 3.4 + 3.2), n(star.r * .5 + .75))).join('')}"/>` : '';
  }).filter(Boolean).join('\n');

  define('ripple', [[0, 'opacity:.85;transform:scale(1)'], [1.1, 'opacity:0;transform:scale(3.2)'], [10, 'opacity:0;transform:scale(3.2)']], 'opacity:0;');
  define('pulse', [[0, 'opacity:0'], [1.75, 'opacity:1'], [3.5, 'opacity:0']], 'opacity:0;');
  const first = scene.stars[0]?.x ?? 0;
  const span = (scene.stars.at(-1)?.x ?? 0) - first;
  const peak = (star: AnimationScene['stars'][number], index: number, all: AnimationScene['stars']): boolean => star.bright
    || (star.count > (all[index - 1]?.count ?? 0) && star.count >= (all[index + 1]?.count ?? 0));
  const ripples = scene.stars.filter((star, index, all) => star.count > 0 && peak(star, index, all)).map(star => {
    const fill = escapeText(star.fill);
    const wave = span > 0 ? (star.x - first) / span * 7 : 0;
    return `<circle class="ripple" cx="${star.x}" cy="${star.y}" r="${n(star.r + 2)}" fill="none" stroke="${fill}" stroke-width="1.2" style="animation-delay:${decimal(seconds(wave) - FRAME_OFFSET)}s;transform-origin:${star.x}px ${star.y}px"/>`
      + (star.bright ? `<circle class="pulse" cx="${star.x}" cy="${star.y}" r="30" fill="url(#flare)" style="${delay(random() * 3)}"/>` : '');
  }).join('\n');

  define('run', [[0, 'stroke-dashoffset:3'], [7, 'stroke-dashoffset:-101'], [10, 'stroke-dashoffset:-101']], PARKED_DASH);
  define('orbit', [[0, 'stroke-dashoffset:9'], [5.5, 'stroke-dashoffset:-128']], PARKED_DASH);
  define('logo', [[0, 'opacity:0'], [2, 'opacity:1'], [4, 'opacity:0']], 'opacity:0;');
  define('screen', [[0, 'opacity:.1'], [1.8, 'opacity:1'], [2.1, 'opacity:.6'], [2.5, 'opacity:1'], [4.5, 'opacity:.1']], 'opacity:0;');
  define('rim', [[0, 'opacity:.1'], [3.5, 'opacity:1'], [7, 'opacity:.1']], 'opacity:0;');
  define('glint', [[0, 'stroke-dashoffset:5'], [9.5, 'stroke-dashoffset:-101'], [16, 'stroke-dashoffset:-101']], PARKED_DASH);
  const shoot = (name: string, from: [number, number], to: [number, number], loop: number): void => {
    const at = (progress: number): string => `transform:translate(${n(from[0] + (to[0] - from[0]) * progress)}px,${n(from[1] + (to[1] - from[1]) * progress)}px)`;
    define(name, [[0, `opacity:0;${at(0)}`], [.13, `opacity:1;${at(.125)}`], [.8, `opacity:1;${at(.77)}`], [1.04, `opacity:0;${at(1)}`], [loop, `opacity:0;${at(1)}`]], 'opacity:0;');
  };
  shoot('s1', [1190, 58], [900, 182], 13);
  shoot('s2', [1500, 120], [1270, 218], 19);
  shoot('s3', [760, 236], [560, 321], 23);

  const hasSegment = /^\s*M[^A-Za-z]*[A-Za-z]/.test(scene.line);
  const line = hasSegment ? `<g fill="none" stroke-linecap="round" stroke-dasharray="3 300"><path class="run" d="${escapeText(scene.line)}" pathLength="100" stroke="#b8fff4" stroke-width="7" stroke-opacity=".22"/><path class="run" d="${escapeText(scene.line)}" pathLength="100" stroke="#f4fffd" stroke-width="2.4"/></g>` : '';
  const layers = [
    twinkles,
    line,
    ripples,
    `<path class="s1" d="M0 0L84-36" stroke="url(#trail)" stroke-width="1.8" stroke-linecap="round"/><path class="s2" style="${delay(7)}" d="M0 0L66-28" stroke="url(#trail)" stroke-width="1.5" stroke-linecap="round"/><path class="s3" style="${delay(15)}" d="M0 0L60-25" stroke="url(#trail)" stroke-width="1.3" stroke-linecap="round"/>`,
    `<g transform="translate(${n(scene.orbit.x)} ${n(scene.orbit.y)})" fill="none" stroke-linecap="round"><circle class="logo" cx="77.5" cy="73.5" r="46" fill="url(#logoGlow)"/>
<path class="orbit" d="${ORBIT_PATH}" pathLength="100" stroke="#bdfff2" stroke-width="7" stroke-dasharray="9 300" stroke-opacity=".25"/>
<path class="orbit" d="${ORBIT_PATH}" pathLength="100" stroke="#f6fffd" stroke-width="2.6" stroke-dasharray="9 300"/>
<path class="t30" fill="#dffeff" fill-opacity=".5" d="${dot(114, 13, 6)}${dot(84, 119, 6)}"/></g>`,
    `<ellipse class="screen" cx="${SCREEN_GLOW.cx}" cy="${SCREEN_GLOW.cy}" rx="${SCREEN_GLOW.rx}" ry="${SCREEN_GLOW.ry}" fill="url(#screenGlow)"/>`,
    `<g fill="none" stroke="#7dfff0" stroke-linecap="round"><path class="rim" d="${PLANET_RIM}" stroke-width="9" stroke-opacity=".16"/><path class="glint" d="${PLANET_RIM}" pathLength="100" stroke="#eafffb" stroke-width="3" stroke-opacity=".85" stroke-dasharray="5 300"/></g>`,
  ].filter(Boolean);

  const label = escapeText(scene.label);
  return `<svg xmlns="http://www.w3.org/2000/svg" width="1920" height="840" viewBox="0 0 1920 840" role="img" aria-label="${label}">
<title>${label}</title>
<defs>
<radialGradient id="flare"><stop stop-color="#7dffc0" stop-opacity=".55"/><stop offset="1" stop-color="#7dffc0" stop-opacity="0"/></radialGradient>
<radialGradient id="logoGlow"><stop stop-color="#52f5a0" stop-opacity=".5"/><stop offset="1" stop-color="#52f5a0" stop-opacity="0"/></radialGradient>
<radialGradient id="screenGlow"><stop stop-color="#48f0e2" stop-opacity=".5"/><stop offset=".45" stop-color="#48f0e2" stop-opacity=".2"/><stop offset="1" stop-color="#48f0e2" stop-opacity="0"/></radialGradient>
<linearGradient id="trail" x1="0" y1="1" x2="1" y2="0"><stop stop-color="#fff"/><stop offset=".25" stop-color="#9ffff2" stop-opacity=".7"/><stop offset="1" stop-color="#9ffff2" stop-opacity="0"/></linearGradient>
</defs>
<style>
${classes.join('\n')}
${rules.join('\n')}
@media (prefers-reduced-motion:reduce){*{animation:none!important}}
</style>
<image href="data:image/jpeg;base64,${baseJpeg.toString('base64')}" width="1920" height="840"/>
<g aria-hidden="true">
${layers.join('\n')}
</g>
</svg>
`;
}
