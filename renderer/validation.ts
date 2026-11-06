import type { ConstellationData } from './types.js';

export const DAY_MS = 86_400_000;
const HEX_COLOR = /^#[\da-f]{6}$/i;
const ISO_UTC = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
const PRIVATE_STATES = ['included', 'excluded', 'unknown'];
const LOGIN_LIMIT = 39;

export function parseDate(value: unknown, label = 'date'): number {
  if (typeof value !== 'string' || !/^(?!0000)\d{4}-\d{2}-\d{2}$/.test(value)) {
    throw new Error(`${label} must use YYYY-MM-DD.`);
  }
  const timestamp = Date.parse(`${value}T00:00:00.000Z`);
  if (!Number.isFinite(timestamp) || new Date(timestamp).toISOString().slice(0, 10) !== value) {
    throw new Error(`${label} is not a valid calendar date.`);
  }
  return timestamp;
}

function object(value: unknown, label: string): Record<string, unknown> {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error(`${label} must be an object.`);
  }
  return value as Record<string, unknown>;
}

function text(value: unknown, label: string, limit = 160): asserts value is string {
  if (typeof value !== 'string' || !value.trim() || value.length > limit || /[\u0000-\u001f]/.test(value)) {
    throw new Error(`${label} must be nonempty text of at most ${limit} characters.`);
  }
}

function integer(value: unknown, label: string): asserts value is number {
  if (!Number.isSafeInteger(value) || (value as number) < 0) {
    throw new Error(`${label} must be a nonnegative safe integer.`);
  }
}

function color(value: unknown, label: string): asserts value is string {
  if (typeof value !== 'string' || !HEX_COLOR.test(value)) {
    throw new Error(`${label} must be a six-digit hexadecimal color.`);
  }
}

function validateSource(input: unknown): void {
  const source = object(input, 'source');
  if (source.provider !== 'github') throw new Error("source.provider must be 'github'.");
  text(source.login, 'source.login', LOGIN_LIMIT);
  integer(source.restrictedContributions, 'source.restrictedContributions');
  integer(source.repositoriesScanned, 'source.repositoriesScanned');
  const totals = object(source.visibleTotals, 'source.visibleTotals');
  for (const key of ['commits', 'issues', 'pullRequests', 'reviews', 'repositories']) {
    integer(totals[key], `source.visibleTotals.${key}`);
  }
}

/** Validate the rendering rendering boundary, including calendar continuity. */
export function validateData(input: unknown): ConstellationData {
  const root = object(input, 'data');
  if (root.schemaVersion !== 2) throw new Error('Unsupported schemaVersion; expected 2.');
  if (root.mode !== 'demo' && root.mode !== 'live') throw new Error("mode must be 'demo' or 'live'.");
  if (typeof root.generatedAt !== 'string' || !ISO_UTC.test(root.generatedAt) || !Number.isFinite(Date.parse(root.generatedAt))) {
    throw new Error('generatedAt must be an ISO 8601 UTC timestamp.');
  }
  const profile = object(root.profile, 'profile');
  for (const key of ['username', 'name', 'role', 'stack']) text(profile[key], `profile.${key}`);
  const theme = object(root.theme, 'theme');
  color(theme.cyan, 'theme.cyan');
  color(theme.green, 'theme.green');
  const period = object(root.period, 'period');
  const start = parseDate(period.start, 'period.start');
  const end = parseDate(period.end, 'period.end');
  const expectedDays = (end - start) / DAY_MS + 1;
  if (expectedDays < 1 || expectedDays > 366) throw new Error('period must contain between 1 and 366 days.');
  if (!Array.isArray(root.days) || root.days.length !== expectedDays) {
    throw new Error('days must cover every day of period, inclusively.');
  }
  let total = 0;
  let active = 0;
  let streak = 0;
  let longest = 0;
  for (const [index, entry] of root.days.entries()) {
    const day = object(entry, `days[${index}]`);
    if (parseDate(day.date, `days[${index}].date`) !== start + index * DAY_MS) {
      throw new Error('days must be ordered and contiguous, without duplicates.');
    }
    integer(day.count, `days[${index}].count`);
    total += day.count;
    if (!Number.isSafeInteger(total)) throw new Error('Contribution total exceeds the safe integer range.');
    if (day.count > 0) { active++; longest = Math.max(longest, ++streak); } else { streak = 0; }
  }
  const metrics = object(root.metrics, 'metrics');
  for (const key of ['totalContributions', 'activeDays', 'longestStreak']) integer(metrics[key], `metrics.${key}`);
  for (const key of ['pullRequests', 'codeReviews']) {
    if (metrics[key] !== null) integer(metrics[key], `metrics.${key}`);
  }
  if (metrics.totalContributions !== total || metrics.activeDays !== active || metrics.longestStreak !== longest) {
    throw new Error('Calendar metrics must agree with the supplied daily contributions.');
  }
  if (!Array.isArray(root.languages) || root.languages.length > 5) throw new Error('languages must contain at most five entries.');
  const names = new Set<string>();
  for (const [index, entry] of root.languages.entries()) {
    const language = object(entry, `languages[${index}]`);
    text(language.name, `languages[${index}].name`, 80);
    if (names.has(language.name.toLowerCase())) throw new Error('languages must not contain duplicates.');
    names.add(language.name.toLowerCase());
    integer(language.repositories, `languages[${index}].repositories`);
    if (language.repositories === 0) throw new Error('A ranked language must appear in at least one repository.');
    color(language.color, `languages[${index}].color`);
  }
  if (!PRIVATE_STATES.includes(root.privateContributions as string)) {
    throw new Error('privateContributions must be included, excluded or unknown.');
  }
  if (root.mode === 'demo') {
    if (root.privateContributions !== 'excluded') throw new Error('Demo data must report privateContributions as excluded.');
    if (root.source !== null) throw new Error('Demo data must have a null source.');
  } else {
    validateSource(root.source);
  }
  return root as unknown as ConstellationData;
}
