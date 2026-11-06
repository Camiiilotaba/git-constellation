# Phase 2 — GitHub Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `--live` mode that builds the approved constellation image from real GitHub GraphQL data for a configured login, with a three-state private-contributions label, no fallback to demo data, and no token leakage.

**Architecture:** PHP fetches two GraphQL queries (contributions calendar + totals, owned public repositories for languages) through a small client over Symfony HttpClient, maps them onto the existing `ContributionCalendar` / `ContributionMetrics` / `LanguageRanking` value objects, and writes `build/data.json` schema v2. The TypeScript renderer learns schema v2 and `mode: "live"`, applies the four approved visual adjustments, and otherwise stays untouched.

**Tech Stack:** PHP 8.3, Symfony HttpClient 7.4 (installed), Symfony Dotenv 7.4 (new), PHPUnit 12.5, TypeScript 5.9, Vitest 4.1, Playwright 1.65. No Laravel, React, Vue, Tailwind.

**Spec:** `docs/superpowers/specs/2026-11-06-phase2-github-integration-design.md`

## Global Constraints

- The token is read from `GITHUB_TOKEN`, optionally populated from `<projectRoot>/.env` via `Dotenv::parse()`. It is never exported to `$_ENV`, `$_SERVER` or `putenv`, never written to any file, never part of any exception message, log line or CLI output.
- Any live failure (network, auth, rate limit, GraphQL `errors`, validation) exits 1 with a message on STDERR and never falls back to demo data. The previous image is not touched.
- GraphQL queries never request repository `name`, `nameWithOwner`, `description` or URLs.
- Minimal token scope documented: classic PAT with only `read:user`. Fine-grained tokens are documented as unverified.
- Default live output path: `dist/live.png`. `dist/constellation.png` stays the committed demo image.
- Visual changes limited to spec §7: remove tagline `ACTIVITY • CODE • CONTRIBUTIONS`, remove card secondary lines, DEMO badge only in demo mode, three subtitle states. Palette, layout, constellation, cards and ranking otherwise unchanged.
- `data.json` schema v2 key order: `schemaVersion, mode, generatedAt, profile, theme, period, days, metrics, languages, privateContributions, source`.
- Private contributions state (user decision 2026-11-06, supersedes spec §5.6): `included` only if `restricted > 0` or `hasAnyRestricted` (explicit GitHub signals); `excluded` only if `restricted == 0` and `!hasAnyRestricted` and `calendarTotal == visibleSum`; **everything else is `unknown`**, including a calendar total above the visible sum with no restricted signal. Never infer private activity from arithmetic alone. Subtitles: `included` → "private counts anonymized", `excluded` → "public activity only", `unknown` → "visible activity only".
- Token with the `repo` scope (user decision 2026-11-06): the run is **rejected** with `GitHubApiException(Auth)` and the message `The token has the repo scope, which is not needed and would expose private activity. Create a classic token with only read:user.` No warning mode. Tokens that report no `x-oauth-scopes` header (fine-grained) are allowed.
- Everything in the repository is written in English: code, identifiers, messages, comments, tests, README and docs. Comments only where they explain a non-obvious why (API limitation, security measure, surprising behaviour); never restate what the code does.
- No unnecessary abstractions, layers or dependencies. Prefer the simplest implementation that satisfies the tests.
- Language color fallback for `color: null`: `#8b949e`.
- Max repository pages: 20 × 100. More → error, never a silently truncated ranking.
- Tests: PHP in `tests/php/`, Vitest in `tests/ts/`, Playwright in `tests/visual/`. Synthetic fixtures only; nothing recorded from a real account.
- Commits: the repository has no commits yet. Perform the commit steps **only if the user has authorized commits** (see handoff). Otherwise skip them and leave the working tree for review.
- Spike-dependent steps are marked **[SPIKE]**. They are finalized once the read-only spike against the user's account has run; the plan states the assumed outcome and the alternative.

## Review Focus

1. **Calendar weeks padded outside the period.** GitHub returns whole weeks, so days before `period.start` (and possibly after `end`) appear. Expected: they are dropped before building `ContributionCalendar`, and the metrics only count in-range days. Test in Task 6.
2. **A user with zero contributions and zero eligible repositories.** Expected: a valid live image with an all-zero calendar, `languages: []`, state `excluded`, no exception. Test in Task 8.
3. **A token that also has the `repo` scope.** Expected: the run is rejected after the first response (scopes come from the `x-oauth-scopes` header), exit 1, nothing written, message names `read:user`. Test in Task 5 (scopes captured) and Task 8 (`LiveStatistics` rejects).
4. **Network down or GitHub 5xx forever.** Expected: retries exhausted, exit 1, `build/data.json` not written, output image untouched, message without token. Test in Task 5 (retry exhaustion) and Task 9 (Application returns 1 and writes nothing).
5. **Config `username` differs from GitHub's login only in letter case.** Expected: accepted; the login GitHub returns is what goes into `source.login`. Test in Task 6.

---

### Task 1: Data contract v2 on both sides (PHP demo path + renderer validation)

**Files:**
- Modify: `src/Data/RendererData.php`
- Modify: `src/Cli/Application.php:42-47` (pass `generatedAt`)
- Modify: `renderer/types.ts`
- Modify: `renderer/validation.ts:43-96`
- Modify: `tests/ts/fixtures.ts`
- Modify: `tests/php/RendererDataTest.php`
- Modify: `tests/ts/validation.test.ts`
- Create: `src/Statistics/VisibleTotals.php`
- Create: `src/Statistics/PrivateContributions.php`

**Interfaces:**
- Produces (PHP):
  - `final readonly class VisibleTotals { public function __construct(public int $commits, public int $issues, public int $pullRequests, public int $reviews, public int $repositories) {} public function sum(): int; /** @return array{commits:int,issues:int,pullRequests:int,reviews:int,repositories:int} */ public function toArray(): array; }` in namespace `GitConstellation\Statistics`.
  - `enum PrivateContributions: string { case Included = 'included'; case Excluded = 'excluded'; case Unknown = 'unknown'; public static function determine(int $calendarTotal, VisibleTotals $visible, int $restrictedContributions, bool $hasAnyRestricted): self; }` in `GitConstellation\Statistics`. Rule: Global Constraints. Negative inputs → `InvalidArgumentException`.
  - `RendererData::fromDemo(Configuration $config, DemoFixture $fixture, DateTimeImmutable $generatedAt): array` emitting v2 with `mode: 'demo'`, `generatedAt` as `Y-m-d\TH:i:s\Z` in UTC, `privateContributions: 'excluded'`, `source: null`.
  - `RendererData::assemble(...)` private helper shared later by `fromLive` (Task 8); signature left to the builder, but both factories must produce the same key order.
- Produces (TS): `ConstellationData` with `schemaVersion: 2; mode: 'demo' | 'live'; generatedAt: string; privateContributions: 'included' | 'excluded' | 'unknown'; source: ConstellationSource | null` where `ConstellationSource = { provider: 'github'; login: string; restrictedContributions: number; visibleTotals: { commits: number; issues: number; pullRequests: number; reviews: number; repositories: number }; repositoriesScanned: number }`. `privateContributionsIncluded` is removed.
- `tests/ts/fixtures.ts` gains `liveData(overrides?: Partial<ConstellationData>): ConstellationData` (v2, `mode: 'live'`, `privateContributions: 'included'`, a filled `source`), and `demoData()` becomes v2.

- [ ] **Step 1: Write failing PHP tests**

In `tests/php/Statistics/PrivateContributionsTest.php` (namespace `GitConstellation\Tests\Statistics`):

```php
#[DataProvider('cases')]
public function testDetermine(int $calendarTotal, array $visible, int $restricted, bool $hasAny, PrivateContributions $expected): void
{
    self::assertSame($expected, PrivateContributions::determine($calendarTotal, new VisibleTotals(...$visible), $restricted, $hasAny));
}
public static function cases(): iterable
{
    yield 'restricted count'      => [120, [50, 10, 20, 15, 5], 20, true,  PrivateContributions::Included];
    yield 'flag only'             => [100, [50, 10, 20, 15, 5], 0,  true,  PrivateContributions::Included];
    yield 'arithmetic gap only'   => [110, [50, 10, 20, 15, 5], 0,  false, PrivateContributions::Unknown];
    yield 'exact match'           => [100, [50, 10, 20, 15, 5], 0,  false, PrivateContributions::Excluded];
    yield 'all zero'              => [0,   [0, 0, 0, 0, 0],     0,  false, PrivateContributions::Excluded];
    yield 'calendar below visible'=> [90,  [50, 10, 20, 15, 5], 0,  false, PrivateContributions::Unknown];
}
public function testNegativeInputRejected(): void // expects InvalidArgumentException for calendarTotal -1
```

In `tests/php/RendererDataTest.php`, replace the v1 assertions in `testCommittedFixtureProducesCompleteVersionOneContract` (rename to `...VersionTwoContract`):

```php
$data = RendererData::fromDemo($config, $fixture, new DateTimeImmutable('2026-11-06T12:00:00Z'));
self::assertSame(['schemaVersion', 'mode', 'generatedAt', 'profile', 'theme', 'period', 'days', 'metrics', 'languages', 'privateContributions', 'source'], array_keys($data));
self::assertSame(2, $data['schemaVersion']);
self::assertSame('demo', $data['mode']);
self::assertSame('2026-11-06T12:00:00Z', $data['generatedAt']);
self::assertSame('excluded', $data['privateContributions']);
self::assertNull($data['source']);
// keep the existing profile/theme/period/days/metrics/languages assertions
```

Add `testGeneratedAtIsNormalizedToUtc`: input `new DateTimeImmutable('2026-11-06T08:00:00-05:00')` → `'2026-11-06T13:00:00Z'`.

- [ ] **Step 2: Run PHP tests to verify they fail**

Run: `./vendor/bin/phpunit tests/php/Statistics/PrivateContributionsTest.php tests/php/RendererDataTest.php`
Expected: FAIL (class `PrivateContributions` not found; `fromDemo` argument count).

- [ ] **Step 3: Implement `VisibleTotals`, `PrivateContributions`, and v2 `RendererData::fromDemo`**

`generatedAt` formatting: `$generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')`. Update `Application.php` to pass `new DateTimeImmutable('now', new DateTimeZone('UTC'))`.

- [ ] **Step 4: Run PHP tests to verify they pass**

Run: `./vendor/bin/phpunit`
Expected: all green (existing 74 + new).

- [ ] **Step 5: Write failing Vitest tests**

In `tests/ts/validation.test.ts` add:

```ts
it('accepts schema v2 demo data', () => { expect(() => validateData(demoData())).not.toThrow(); });
it('rejects schemaVersion 1', () => { expect(() => validateData({ ...demoData(), schemaVersion: 1 })).toThrow(/schemaVersion/); });
it('accepts live data with each privateContributions state', () => {
  for (const state of ['included', 'excluded', 'unknown'] as const) expect(() => validateData(liveData({ privateContributions: state }))).not.toThrow();
});
it('rejects included/unknown in demo mode', () => {
  expect(() => validateData({ ...demoData(), privateContributions: 'included' })).toThrow(/demo/i);
  expect(() => validateData({ ...demoData(), privateContributions: 'unknown' })).toThrow(/demo/i);
});
it('requires source in live mode and null source in demo mode', () => {
  expect(() => validateData(liveData({ source: null }))).toThrow(/source/);
  expect(() => validateData({ ...demoData(), source: liveData().source })).toThrow(/source/);
});
it('validates generatedAt as ISO 8601 UTC', () => {
  expect(() => validateData({ ...demoData(), generatedAt: '2026-11-06 12:00' })).toThrow(/generatedAt/);
});
it('rejects an unknown mode', () => { expect(() => validateData({ ...demoData(), mode: 'test' })).toThrow(/mode/); });
```

Update any existing test that referenced `privateContributionsIncluded` or `schemaVersion: 1` to the v2 shape.

- [ ] **Step 6: Run Vitest to verify failure**

Run: `npm test -- tests/ts/validation.test.ts`
Expected: FAIL (type errors from `tsc` in `pretest` and/or assertion failures).

- [ ] **Step 7: Implement v2 in `renderer/types.ts` and `renderer/validation.ts`; update `tests/ts/fixtures.ts`**

Validation rules: `schemaVersion === 2`; `mode` in `['demo','live']`; `generatedAt` matches `/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/` and `Date.parse` is finite; `privateContributions` in the three values; demo → must be `'excluded'` and `source === null`; live → `source` object with `provider === 'github'`, `login` text ≤ 39, `restrictedContributions` integer, `visibleTotals` five integers, `repositoriesScanned` integer. Replace the `privateContributionsIncluded` checks.

- [ ] **Step 8: Run the whole TS suite**

Run: `npm test`
Expected: PASS. (Visual tests are not run here; the template still reads `privateContributionsIncluded` until Task 2 — replace that read with `data.privateContributions === 'included'` now so the template compiles.)

- [ ] **Step 9: Regenerate the demo end to end**

Run: `composer run demo`
Expected: exit 0, `build/data.json` has `"schemaVersion": 2`, image regenerated.

- [ ] **Step 10: Commit** (only if authorized)

```bash
git add src/ renderer/ tests/ && git commit -m "feat: data contract v2 with privateContributions state and source"
```

---

### Task 2: Approved visual adjustments and live-mode template

**Files:**
- Modify: `renderer/template.ts:67-111`
- Modify: `assets/constellation.css:138-160, 418-430` (tagline rules removed; `.metric small` kept for "Not available"; minimal spacing fix)
- Modify: `tests/ts/render.test.ts`
- Modify: `tests/visual/constellation.spec.ts`
- Snapshots: `tests/visual/constellation.spec.ts-snapshots/*.png` (deliberate one-time update)

**Interfaces:**
- Consumes: `ConstellationData` v2 from Task 1, `liveData()` / `demoData()` from `tests/ts/fixtures.ts`.
- Produces: `renderHtml(data, assets)` output with the mode-dependent copy below. `metric(label: string, value: number | null, name: string, title?: string): string` (the `detail` parameter is removed).

Mode-dependent copy (exact strings):

| Element | demo | live |
|---|---|---|
| `<title>` suffix | `· Git Constellation · Demo` | `· Git Constellation` |
| `.demo` badge | rendered, `DEMO / SIMULATED DATA` | not rendered |
| `main` aria-label | `<name>: simulated GitHub contribution constellation` | `<name>: GitHub contribution constellation` |
| `.contributions` aria-label | `Simulated contributions` | `GitHub contributions` |
| `.privacy em` | `· public activity only` | `· private counts anonymized` / `· public activity only` / `· visible activity only` by state |
| `.privacy` icon | `calendar` | `lock` if included, else `calendar` |
| empty chart text | `No contributions in this example period` | `No contributions in this period` |
| no-languages text | `No eligible public<br/>repositories in this example.` | `No eligible public<br/>repositories.` |
| PR / Reviews card `title` | `Simulated` | `Visible to the token; may exclude private activity` |
| sr-only paragraph | unchanged | `Live GitHub statistics for <username>, generated <generatedAt>. <total> contributions; <active> active days. Pull requests and code reviews count activity visible to the token and may exclude private activity. Language ranking counts eligible public repositories, including secondary languages.` |

The `.tagline` div and the four card `<small>` detail lines are removed in both modes. `<small>Not available</small>` remains only when the value is `null`.

- [ ] **Step 1: Write failing render tests** in `tests/ts/render.test.ts`

```ts
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
  for (const text of ['Created · simulated', 'Submitted · simulated', 'In the example period', 'Consecutive active days']) expect(html).not.toContain(text);
  const nulls = renderHtml(liveData({ metrics: { ...liveData().metrics, pullRequests: null } }), assets);
  expect(nulls).toContain('<small data-fit data-min-size="15">Not available</small>');
});
it('labels PR and review cards as token-visible in live mode', () => {
  expect(renderHtml(liveData(), assets)).toContain('title="Visible to the token; may exclude private activity"');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `npm test -- tests/ts/render.test.ts`
Expected: FAIL on the new assertions.

- [ ] **Step 3: Implement the template and CSS changes**

Remove `.brand .tagline` and `.brand .tagline b` rules from the CSS. Keep `.brand` vertical rhythm so `DEVELOPER CONSTELLATION` and the badge keep their current positions in demo (compare against `build/review/new.png`). For the cards, remove the `<small>` line except for `null`; adjust `.metric-copy` so `strong` + `span` stay vertically centered in the card.

- [ ] **Step 4: Run Vitest**

Run: `npm test`
Expected: PASS.

- [ ] **Step 5: Add live visual tests** in `tests/visual/constellation.spec.ts`

```ts
test('live mode renders without the demo badge', async ({ page }) => {
  await render(page, liveData());
  await expect(page.locator('main')).toHaveScreenshot('live.png');
  await expect(page.locator('.demo')).toHaveCount(0);
  await expect(page.locator('.tagline')).toHaveCount(0);
  await expect(page.locator('.privacy')).toContainText('private counts anonymized');
  await assertNoMetricOverflow(page);
});
```

Extend the existing `for (const width of [960, 830])` README-fit test so it also exports `liveData()` to `build/visual-preview/live.json` → `live.png` and checks it at 830 px the same way (snapshot name `live-readme-830.png`).

- [ ] **Step 6: Update snapshots once and then verify without updating**

Run: `npm run test:visual:update` then `npm run test:visual`
Expected: second run PASS with all snapshots matching. Report in the task summary which snapshot files changed (demo ones change because of the tagline/card edits; this is the deliberate update the spec allows).

- [ ] **Step 7: Regenerate demo and inspect**

Run: `composer run demo && node build/renderer/review.js`
Expected: `dist/constellation.png` without the tagline and without card details; badge still present; everything else identical to the approved render.

- [ ] **Step 8: Commit** (only if authorized)

```bash
git add renderer/ assets/ tests/ dist/constellation.png && git commit -m "feat: live-mode template copy and approved visual adjustments"
```

---

### Task 3: Rolling period

**Files:**
- Create: `src/Data/Period.php`
- Test: `tests/php/Data/PeriodTest.php`

**Interfaces:**
- Produces: `final readonly class Period { private function __construct(public string $start, public string $end) {} public static function trailingYear(DateTimeImmutable $now): self; public function fromDateTime(): string; public function toDateTime(): string; public function contains(string $date): bool; public function dayCount(): int; }` in `GitConstellation\Data`.
  - `$now` is converted to UTC; `end` = that UTC date; `start` = `end - 1 year + 1 day` (PHP `sub(P1Y)->add(P1D)` semantics, so a leap `end` overflows per PHP rules).
  - `fromDateTime()` = `"{start}T00:00:00Z"`, `toDateTime()` = `"{end}T23:59:59Z"`.

- [ ] **Step 1: Write failing tests**

```php
public function testTrailingYearForOrdinaryDate(): void
{
    $period = Period::trailingYear(new DateTimeImmutable('2026-11-06T10:00:00Z'));
    self::assertSame('2025-11-07', $period->start);
    self::assertSame('2026-11-06', $period->end);
    self::assertSame(365, $period->dayCount());
    self::assertSame('2025-11-07T00:00:00Z', $period->fromDateTime());
    self::assertSame('2026-11-06T23:59:59Z', $period->toDateTime());
}
public function testPeriodSpanningLeapDayHas366Days(): void
{
    $period = Period::trailingYear(new DateTimeImmutable('2028-03-01T00:00:00Z'));
    self::assertSame('2027-03-02', $period->start);
    self::assertSame(366, $period->dayCount());
}
public function testNowIsConvertedToUtcDate(): void
{
    $period = Period::trailingYear(new DateTimeImmutable('2026-11-06T23:30:00-05:00'));
    self::assertSame('2026-11-07', $period->end);
}
public function testContainsIsInclusive(): void // start and end true, start-1 and end+1 false
```

- [ ] **Step 2: Run to verify failure** — `./vendor/bin/phpunit tests/php/Data/PeriodTest.php` → FAIL (class not found).
- [ ] **Step 3: Implement `Period`.**
- [ ] **Step 4: Run to verify pass** — same command → PASS.
- [ ] **Step 5: Commit** (only if authorized): `git add src/Data/Period.php tests/php/Data/PeriodTest.php && git commit -m "feat: rolling 12-month period"`

---

### Task 4: Environment and token loading

**Files:**
- Modify: `composer.json` (add `"symfony/dotenv": "^7.4"` to `require`), `composer.lock` via `composer require symfony/dotenv:^7.4`
- Create: `src/Config/Environment.php`
- Modify: `.env.example`
- Test: `tests/php/Config/EnvironmentTest.php`

**Interfaces:**
- Produces: `final readonly class Environment { private function __construct(#[\SensitiveParameter] public string $githubToken) {} /** @param array<string, string|false> $processEnvironment */ public static function load(string $projectRoot, array $processEnvironment): self; }` in `GitConstellation\Config`.
  - Order: `$processEnvironment['GITHUB_TOKEN']` if non-empty; else `<projectRoot>/.env` parsed with `Symfony\Component\Dotenv\Dotenv::parse()` if the file is readable; else error.
  - Validation: non-empty, no whitespace, no `\p{C}` characters. Error message (exact): `GITHUB_TOKEN is not set. Live mode needs a GitHub token with the read:user scope in the environment or in <projectRoot>/.env.` and for malformed: `GITHUB_TOKEN contains whitespace or control characters.` Neither message may include the value.
  - Throws `RuntimeException`.

- [ ] **Step 1: Add the dependency**

Run: `composer require symfony/dotenv:^7.4`
Expected: `composer.lock` updated; `composer validate --strict` passes.

- [ ] **Step 2: Write failing tests** (use a temp directory per test with `sys_get_temp_dir()`; never touch the real `.env`)

```php
public function testProcessEnvironmentWins(): void   // .env has GITHUB_TOKEN=file, env has 'proc' → 'proc'
public function testDotEnvIsParsedWithQuotesAndExport(): void // file: "export GITHUB_TOKEN=\"ghp_abc\"\n" → 'ghp_abc'
public function testMissingEverywhereFails(): void   // RuntimeException, message contains 'read:user' and the project root
public function testMalformedTokenFails(): void      // env 'abc def' → message does not contain 'abc def'
public function testDotEnvIsNeverExportedToProcess(): void // after load, getenv('GITHUB_TOKEN') === false and !isset($_ENV['GITHUB_TOKEN'])
```

- [ ] **Step 3: Run to verify failure** — `./vendor/bin/phpunit tests/php/Config/EnvironmentTest.php` → FAIL.
- [ ] **Step 4: Implement `Environment::load`.**
- [ ] **Step 5: Run to verify pass**, then `./vendor/bin/phpunit` → all PASS.
- [ ] **Step 6: Update `.env.example`** to:

```
# Live mode reads GITHUB_TOKEN from the process environment, or from this file
# when copied to .env in the project root. Demo mode never reads it.
# Create a classic personal access token with ONLY the read:user scope.
# Do not grant repo: it is not needed and would expose private PR/review counts.
# Never put a token in config, fixtures, command arguments, or generated files.
# GITHUB_TOKEN=
```

- [ ] **Step 7: Commit** (only if authorized): `git add composer.json composer.lock src/Config/Environment.php tests/php/Config/EnvironmentTest.php .env.example && git commit -m "feat: load GITHUB_TOKEN from environment or .env"`

---

### Task 5: GraphQL client with error mapping and retries

**Files:**
- Create: `src/GitHub/GraphQlClient.php`, `src/GitHub/GitHubApiException.php`, `src/GitHub/GitHubErrorKind.php`
- Test: `tests/php/GitHub/GraphQlClientTest.php` (uses `Symfony\Component\HttpClient\MockHttpClient` and `MockResponse`)

**Interfaces:**
- Produces (namespace `GitConstellation\GitHub`):
  - `enum GitHubErrorKind: string { case Auth = 'auth'; case RateLimit = 'rateLimit'; case NotFound = 'notFound'; case Transport = 'transport'; case Response = 'response'; }`
  - `final class GitHubApiException extends RuntimeException { public function __construct(public readonly GitHubErrorKind $kind, string $message, ?Throwable $previous = null) }`
  - `final class GraphQlClient { public const string ENDPOINT = 'https://api.github.com/graphql'; public const string USER_AGENT = 'git-constellation'; public function __construct(private HttpClientInterface $http, #[\SensitiveParameter] private string $token, private int $maxRetries = 2, private ?Closure $sleep = null) {} /** @param array<string, mixed> $variables @return array<string, mixed> the GraphQL `data` object */ public function query(string $query, array $variables = []): array; /** @return list<string> scopes from the last x-oauth-scopes header, [] if absent */ public function lastScopes(): array; }`
  - Request: POST JSON `{query, variables}`, headers `Authorization: Bearer <token>`, `User-Agent`, `Accept: application/json`, `timeout: 30`, `max_duration: 60`. Response body limit 5 MB (reject larger with `Response`).
  - Mapping: 401 → `Auth` ("GitHub rejected the token (HTTP 401). Check GITHUB_TOKEN and the read:user scope."); 403/429 with header `x-ratelimit-remaining: 0` or any 429 → `RateLimit` with the reset time from `x-ratelimit-reset` (unix → `Y-m-d\TH:i:s\Z`) or `retry-after` seconds; other 4xx → `Response` with status; 5xx and `TransportExceptionInterface` → retry up to `$maxRetries` with `$sleep(1)`, `$sleep(2)` then `Transport`; non-JSON body → `Response`; body with `errors` (even alongside `data`) → `Response` with messages joined by `; `; missing `data` → `Response`.
  - `$sleep` defaults to `sleep(...)`; tests inject `fn (int $seconds) => $calls[] = $seconds`.

- [ ] **Step 1: Write failing tests**

```php
public function testSendsBearerTokenAndUserAgent(): void // inspect $options['normalized_headers'] via MockHttpClient callback; assert authorization header equals "Bearer tok_123"
public function testReturnsDataObject(): void            // body {"data":{"viewer":{"login":"x"}}} → ['viewer' => ['login' => 'x']]
public function testCapturesScopesHeader(): void          // response header x-oauth-scopes: "read:user, repo" → lastScopes() === ['read:user', 'repo']
public function testUnauthorizedMapsToAuth(): void        // 401 → kind Auth, message contains 'read:user'
public function testRateLimitMapsWithReset(): void        // 403 + x-ratelimit-remaining: 0 + x-ratelimit-reset: 1794000000 → kind RateLimit, message contains '2026-11-06T' prefix of that timestamp (compute with gmdate)
public function testTooManyRequestsMapsToRateLimit(): void // 429 + retry-after: 30 → kind RateLimit, message contains '30'
public function testServerErrorsRetryThenFail(): void     // three 503 responses → kind Transport, sleep calls [1, 2], exactly 3 requests
public function testClientErrorsDoNotRetry(): void        // one 422 → kind Response, exactly 1 request
public function testGraphQlErrorsFailEvenWithData(): void // {"data":{...},"errors":[{"message":"boom"}]} → kind Response, message contains 'boom'
public function testNonJsonBodyFails(): void              // body "<html>" → kind Response
public function testTokenNeverAppearsInMessages(): void   // for each failing scenario above, assertStringNotContainsString('tok_123', $e->getMessage()) and same for $e->getPrevious()?->getMessage()
```

- [ ] **Step 2: Run to verify failure** — `./vendor/bin/phpunit tests/php/GitHub/GraphQlClientTest.php` → FAIL.
- [ ] **Step 3: Implement the three classes.**
- [ ] **Step 4: Run to verify pass** → PASS; then `./vendor/bin/phpunit` → all PASS.
- [ ] **Step 5: Commit** (only if authorized): `git add src/GitHub tests/php/GitHub && git commit -m "feat: GitHub GraphQL client with error mapping"`

---

### Task 6: Contributions query and parsing

**Files:**
- Create: `src/GitHub/ContributionsQuery.php`, `src/GitHub/ContributionsResult.php`
- Create: `tests/php/fixtures/github/contributions-ok.json` (synthetic: login `octo-sample`, 54 weeks, first week padded before the period, five visible totals, restricted 7, hasAny true, rateLimit remaining 4990), `contributions-no-restricted.json`, `contributions-missing-day.json`, `contributions-user-null.json`
- Test: `tests/php/GitHub/ContributionsQueryTest.php`

**Interfaces:**
- Consumes: `GraphQlClient::query`, `Period` (Task 3), `ContributionCalendar::fromArray(array $period, array $days)`, `VisibleTotals` (Task 1).
- Produces:
  - `final class ContributionsQuery { public const string QUERY = <<<'GRAPHQL' ... GRAPHQL; public function __construct(private GraphQlClient $client) {} public function fetch(string $login, Period $period): ContributionsResult; }` — `QUERY` is spec §5.4 query A verbatim (with the three extra totals).
  - `final readonly class ContributionsResult { public function __construct(public string $login, public ContributionCalendar $calendar, public int $calendarTotal, public VisibleTotals $visibleTotals, public int $restrictedContributions, public bool $hasAnyRestrictedContributions, public int $rateLimitRemaining) {} }`
  - Parsing rules: `data.user === null` → `GitHubApiException(NotFound, "GitHub user not found: <login>")`; returned `login` compared with `strcasecmp` — mismatch → `Response`; `$login` returned by GitHub is what `ContributionsResult::$login` holds; days outside `$period->contains()` are dropped; days are passed to `ContributionCalendar::fromArray(['start' => ..., 'end' => ...], $days)` and an `InvalidArgumentException` from it is rethrown as `GitHubApiException(Response, ...)`; every integer field is validated with `IntegerRange::nonNegative`; missing fields → `Response`.
  - **[SPIKE]** Consistency check: `calendarTotal` must equal the sum of **all returned days** (including padded ones). Assumed outcome of the spike: GitHub's `totalContributions` counts every returned day. If the spike shows it counts only in-range days, change the check to the in-range sum. Either way a mismatch → `GitHubApiException(Response, 'GitHub calendar total does not match its daily counts')`.

- [ ] **Step 1: Write failing tests** (use `MockHttpClient` returning the fixture bodies; `Period::trailingYear(new DateTimeImmutable('2026-11-06T00:00:00Z'))`)

```php
public function testParsesCalendarAndTotals(): void          // calendar->start/end equal the period; count(days) === 365; padded days dropped; visibleTotals->toArray() === fixture values; restricted 7; hasAny true; rateLimitRemaining 4990; calendarTotal === fixture total
public function testSendsLoginAndPeriodVariables(): void     // captured request body JSON has variables {login, from: '2025-11-07T00:00:00Z', to: '2026-11-06T23:59:59Z'}
public function testLoginCaseIsNormalizedFromGitHub(): void  // fetch('OCTO-SAMPLE') → result->login === 'octo-sample'
public function testUnknownUserIsNotFound(): void            // user null → kind NotFound
public function testLoginMismatchFails(): void               // fixture login 'someone-else' → kind Response
public function testMissingDayFails(): void                  // kind Response, message mentions 'calendar'
public function testCalendarTotalMismatchFails(): void       // alter totalContributions by +1 → kind Response
public function testNoRestrictedCountsParse(): void          // restricted 0 / hasAny false parse fine
```

- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement `ContributionsQuery` and `ContributionsResult`; author the four fixtures.** Fixture days: generate deterministically (e.g. `count = (dayIndex * 7) % 5`) so totals are reproducible; document the generator formula in a comment at the top of the test.
- [ ] **Step 4: Run to verify pass**, then full `./vendor/bin/phpunit` → PASS.
- [ ] **Step 5: Commit** (only if authorized): `git commit -m "feat: contributions query and parsing"`

---

### Task 7: Repositories query for the language ranking

**Files:**
- Create: `src/GitHub/RepositoriesQuery.php`, `src/GitHub/RepositoriesResult.php`
- Create: `tests/php/fixtures/github/repositories-page-1.json`, `-page-2.json`, `-page-3.json` (synthetic; include an archived repo, a `color: null` language, a repo with no languages)
- Test: `tests/php/GitHub/RepositoriesQueryTest.php`

**Interfaces:**
- Consumes: `GraphQlClient::query`.
- Produces:
  - `final class RepositoriesQuery { public const string QUERY = <<<'GRAPHQL' ... GRAPHQL; public const int PAGE_SIZE = 100; public const int MAX_PAGES = 20; public const string FALLBACK_COLOR = '#8b949e'; public function __construct(private GraphQlClient $client) {} public function fetch(string $login): RepositoriesResult; }` — `QUERY` is spec §5.4 query B verbatim.
  - `final readonly class RepositoriesResult { /** @param list<array{visibility: string, isFork: bool, isArchived: bool, languages: list<array{name: string, color: string}>}> $repositories */ public function __construct(public array $repositories, public int $scanned) {} }` — `$repositories` is shaped exactly for `LanguageRanking::calculate`; `$scanned` counts every node returned by GitHub (before `LanguageRanking` filtering).
  - Pagination follows `pageInfo.hasNextPage` / `endCursor`; after `MAX_PAGES` pages with `hasNextPage` still true → `GitHubApiException(Response, 'More than 2000 public repositories; refusing to rank languages on a partial list')`. `data.user === null` → `NotFound`. `color: null` → `FALLBACK_COLOR`.

- [ ] **Step 1: Write failing tests**

```php
public function testPaginatesUntilLastPage(): void      // 3 fixture pages → 3 requests, scanned === sum of nodes, second request carries after === page-1 endCursor
public function testNullColorGetsFallback(): void       // a language with color null → '#8b949e' in the output and still counted by LanguageRanking::calculate
public function testArchivedAndForksArePassedThroughForRankingToFilter(): void // output keeps isArchived true entries; LanguageRanking::calculate(result->repositories) excludes them
public function testTooManyPagesFails(): void           // MockHttpClient yielding hasNextPage true forever → kind Response after exactly 20 requests
public function testUnknownUserIsNotFound(): void
```

- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass**, full suite PASS.
- [ ] **Step 5: Commit** (only if authorized): `git commit -m "feat: repositories query for language ranking"`

---

### Task 8: Live snapshot, orchestration and `RendererData::fromLive`

**Files:**
- Create: `src/Data/LiveSnapshot.php`, `src/GitHub/LiveStatistics.php`
- Modify: `src/Data/RendererData.php` (add `fromLive`, reuse the private assembler from Task 1)
- Test: `tests/php/GitHub/LiveStatisticsTest.php`, `tests/php/Data/RendererDataLiveTest.php`

**Interfaces:**
- Consumes: `ContributionsQuery::fetch`, `RepositoriesQuery::fetch`, `PrivateContributions::determine`, `LanguageRanking::calculate`, `ContributionMetrics::calculate`.
- Produces:
  - `final readonly class LiveSnapshot { /** @param list<array{name: string, repositories: int, color: string}> $languages */ public function __construct(public string $login, public ContributionCalendar $calendar, public int $pullRequests, public int $codeReviews, public VisibleTotals $visibleTotals, public int $restrictedContributions, public PrivateContributions $privateContributions, public array $languages, public int $repositoriesScanned, public DateTimeImmutable $generatedAt, public int $rateLimitRemaining, /** @var list<string> */ public array $tokenScopes) {} }`
  - `final class LiveStatistics { public function __construct(private GraphQlClient $client, private ContributionsQuery $contributions, private RepositoriesQuery $repositories) {} public function collect(string $login, Period $period, DateTimeImmutable $generatedAt): LiveSnapshot; }` — after the contributions query, if `$client->lastScopes()` contains `repo` → throw `GitHubApiException(Auth, ...)` with the message from Global Constraints, before the repositories query. `pullRequests` = `visibleTotals->pullRequests`, `codeReviews` = `visibleTotals->reviews`, `privateContributions` = `PrivateContributions::determine(calendarTotal, visibleTotals, restricted, hasAny)`, `languages` = `LanguageRanking::calculate($repositories->repositories)`, `tokenScopes` = `$client->lastScopes()`.
  - `RendererData::fromLive(Configuration $config, LiveSnapshot $snapshot): array` → v2 with `mode: 'live'`, `profile.username` = `$snapshot->login`, `privateContributions` = enum value, `source` = `['provider' => 'github', 'login' => ..., 'restrictedContributions' => ..., 'visibleTotals' => $visibleTotals->toArray(), 'repositoriesScanned' => ...]`.
  - **[SPIKE]** `metrics.totalContributions` is computed by `ContributionMetrics` from in-range days (what the image shows). The arithmetic for `PrivateContributions::determine` uses `calendarTotal` as GitHub reports it. If the spike shows `calendarTotal` includes padded out-of-range days, `LiveStatistics` must pass the in-range sum instead; note the decision in `docs/phase-2.md` (Task 10).

- [ ] **Step 1: Write failing tests**

`LiveStatisticsTest` (MockHttpClient with the Task 6/7 fixtures in sequence):

```php
public function testCollectsSnapshot(): void          // login, 365 days, pullRequests/codeReviews from visible totals, Included state, languages sorted top 5, repositoriesScanned, tokenScopes ['read:user'], generatedAt passed through
public function testZeroActivityAndNoRepositories(): void // all-zero calendar fixture + empty repositories page → Excluded, languages [], no exception
public function testContributionsFailureAbortsBeforeRepositories(): void // first response 401 → GitHubApiException Auth, exactly 1 request made
public function testRepoScopeIsRejected(): void       // first response header x-oauth-scopes: "read:user, repo" → GitHubApiException Auth, message contains 'read:user', exactly 1 request made
public function testMissingScopesHeaderIsAllowed(): void // no x-oauth-scopes header → snapshot built, tokenScopes []
```

`RendererDataLiveTest`:

```php
public function testFromLiveProducesVersionTwoContract(): void // key order per Global Constraints; mode 'live'; profile.username === snapshot login; privateContributions 'included'; source array equals expected; metrics equal ContributionMetrics + visible PR/reviews
public function testFromLiveStateValuesSerializeAsStrings(): void // 'excluded' and 'unknown'
public function testDemoAndLiveShareKeyOrder(): void
```

- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement `LiveSnapshot`, `LiveStatistics`, `RendererData::fromLive`.**
- [ ] **Step 4: Run to verify pass**; full `./vendor/bin/phpunit` PASS; `npm test` PASS (validate a `fromLive` sample through the TS validator by writing `build/live-sample.json` in the PHP test and feeding it to `node -e` is optional; the Vitest `liveData()` fixture already covers the shape).
- [ ] **Step 5: Commit** (only if authorized): `git commit -m "feat: live statistics snapshot and renderer data"`

---

### Task 9: CLI `--live`, application wiring, summary, child-process environment

**Files:**
- Modify: `src/Cli/Arguments.php`, `src/Cli/Application.php`, `src/Render/NodeExporter.php`
- Create: `src/Cli/Summary.php`, `config/live.example.json`
- Test: `tests/php/ArgumentsTest.php` (extend), `tests/php/Cli/SummaryTest.php`, `tests/php/Cli/ApplicationLiveTest.php`, `tests/php/NodeExporterTest.php` (extend)

**Interfaces:**
- `Arguments`: add `public bool $live`. Rules: `--live` is a flag (no value); `--demo` and `--live` together → `InvalidArgumentException('Choose either --demo or --live, not both.')`; neither → `'Pass --demo (simulated data) or --live (GitHub data). Use --help for usage.'`; `--fixture` with `--live` → `'--fixture only applies to --demo.'`. Help text updated to describe both modes and the token requirement (builder writes the copy; must mention `read:user`, `.env`, `dist/live.png`).
- `Application::__construct(private readonly string $projectRoot, private readonly ?HttpClientInterface $http = null, private readonly ?DateTimeImmutable $now = null)` — `$http` defaults to `HttpClient::create()`, `$now` to UTC now. Live flow: `Environment::load($this->projectRoot, getenv())` → `Period::trailingYear($now)` → `GraphQlClient` → `LiveStatistics::collect($config->username, $period, $now)` → `JsonFile::write(build/data.json, RendererData::fromLive(...))` → `fwrite(STDOUT, Summary::format($snapshot, $period))` → `NodeExporter::export` → `"Generated live image: {$output}\n"`. `GitHubApiException` is a `RuntimeException`, so the existing catch covers it. On any exception before `JsonFile::write`, nothing is written.
- `final class Summary { public static function format(LiveSnapshot $snapshot, Period $period): string; }` exact layout:

```
LIVE — GitHub statistics for <login>
Period (UTC): <start> .. <end>
Generated at: <generatedAt Z>
Contributions (calendar): <total>
Private contributions: <included|excluded|unknown> (restricted count: <n>)
Pull Requests (visible to token): <n>
Code Reviews (visible to token): <n>
Active Days: <n>
Longest Streak: <n>
Repositories scanned (public, owned, non-fork): <n>
Top Languages: PHP (8), TypeScript (4) | none
Token scopes: read:user | (not reported)
Rate limit remaining: <n>
```
  (no warning line: a `repo` token is rejected in Task 8 before a summary exists).
- `NodeExporter`: add `public static function childEnvironment(array $environment): array` removing every key matching `/TOKEN|SECRET|PASSWORD/i`; `export()` passes `self::childEnvironment(getenv())` as the fifth `proc_open` argument.
- `config/live.example.json`:

```json
{
    "username": "your-github-login",
    "name": "Your Name",
    "role": "Your Role",
    "stack": "Your / Stack",
    "output": "dist/live.png",
    "colors": { "cyan": "#30e8ef", "green": "#52f5a0" }
}
```

- [ ] **Step 1: Write failing tests**

`ArgumentsTest` additions: `testLiveFlagParses`, `testDemoAndLiveAreExclusive`, `testNeitherModeFails`, `testFixtureRejectedInLiveMode`, `testLiveDoesNotTakeValue`.

`SummaryTest`: `testFormatsAllLines` (build a `LiveSnapshot` by hand; assert the exact multi-line string); `testNoLanguagesPrintsNone`; `testMissingScopesPrintsNotReported`.

`ApplicationLiveTest` (uses a temp project root containing `config/local.json` and a fake `build/renderer/export.js` is **not** needed: make the HTTP mock fail so the run stops before rendering):
```php
public function testLiveFailureWritesNothingAndReturnsOne(): void // MockHttpClient 401; putenv not used: pass token via a .env in the temp root; assert run(['--live','--config=config/local.json']) === 1, no build/data.json, STDERR message contains 'HTTP 401' and not the token
public function testLiveWithoutTokenFailsBeforeAnyRequest(): void // no .env, no env → 1, zero HTTP requests
```
(Capture STDERR by running the CLI in a subprocess with `proc_open` on `bin/constellation`, or by refactoring `Application` to accept output streams — builder's choice; the assertions above are what matters.)

`NodeExporterTest` addition: `testChildEnvironmentStripsSecrets` — `['PATH' => '/usr/bin', 'GITHUB_TOKEN' => 'x', 'MY_SECRET' => 'y', 'DB_PASSWORD' => 'z', 'HOME' => '/h']` → `['PATH' => '/usr/bin', 'HOME' => '/h']`.

- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `./vendor/bin/phpunit` → PASS; `composer run demo` still works; `php bin/constellation --live --config=config/live.example.json` without a token → exit 1 with the `read:user` message.
- [ ] **Step 5: Commit** (only if authorized): `git commit -m "feat: --live mode with verification summary"`

---

### Task 10: Integration test and documentation

**Files:**
- Create: `tests/php/Integration/GitHubLiveTest.php`
- Create: `docs/phase-2.md`
- Modify: `README.md`, `renderer/README.md`, `fixtures/README.md`, `docs/phase-1.md` (one line pointing to phase 2 for the v2 contract)

**Interfaces:**
- Integration test: `#[Group('integration')]`; `markTestSkipped` unless `getenv('GITHUB_TOKEN')` and `getenv('GIT_CONSTELLATION_LOGIN')` are non-empty. Uses the real `HttpClient::create()`. Asserts: `LiveStatistics::collect` returns 365/366 days, `calendar` start/end equal the period, `rateLimitRemaining > 0`, `privateContributions` is one of the three states, and no exception. Does not assert concrete numbers.

- [ ] **Step 1: Write the integration test and run it twice**

Run: `./vendor/bin/phpunit --group integration` → `S` (skipped) without env. Then, only if the user's `.env` exists, run with `GITHUB_TOKEN="$(grep ^GITHUB_TOKEN .env | cut -d= -f2-)" GIT_CONSTELLATION_LOGIN=<login> ./vendor/bin/phpunit --group integration` → PASS. Never print the token.

- [ ] **Step 2: Write `docs/phase-2.md`** with sections: Objetivo; Contrato v2 (full example with live values); Consultas GraphQL (A and B verbatim); Permisos y token (classic `read:user`, fine-grained unverified, why not `repo`); Qué incluye actividad privada (table from spec §5.6); Estado de contribuciones privadas (rule + spike result, filled by the reviewer); Período móvil; Criterio de lenguajes; Errores y comportamiento sin fallback; Verificación manual (spec §6 procedure, four steps); Limitaciones conocidas; Compatibilidad prevista con Quick Start / Advanced Setup (spec §4.1, no implementation).

- [ ] **Step 3: Rewrite `README.md` in English** (the current file is Spanish; the user requires every repository document in English), keeping its structure and content, and add a new section "Modo live (Advanced Setup)" after the demo section: token creation steps, `.env`, `config/live.example.json` → `config/local.json`, the command, reading the summary, where the image goes (`dist/live.png`), failure behaviour; a "Qué incluye actividad privada" table; the language criterion sentence from spec §7.3; a short "Quick Start (próximamente, Fase 3)" placeholder stating only that a reusable GitHub Actions workflow is planned. Update "Alcance por fases" and "Privacidad y estado de GitHub".

- [ ] **Step 4: Update `renderer/README.md`** (contract v2, both modes, mode-dependent copy table from Task 2) and `fixtures/README.md` (fixture still v1-shaped input; output is v2). Translate `docs/phase-1.md` to English, keeping content. `docs/phase-2.md` is written in English from the start.

- [ ] **Step 5: Verify docs commands** — run every command block in the README's live section against `config/live.example.json` (expect the token error) and the demo section (expect success).

- [ ] **Step 6: Commit** (only if authorized): `git commit -m "docs: phase 2 live mode, privacy and limitations"`

---

## Reviewer-only closing steps (not delegated)

1. Run the full suites: `composer validate --strict && ./vendor/bin/phpunit && npm test && npm run test:visual`.
2. With the user's `.env` in place: `php bin/constellation --live --config=config/local.json`, read the summary, compare against a direct GraphQL `curl` with the same variables (spec §6 step 1), spot-check five dates against the profile graph (step 2), and record the result in `docs/phase-2.md`.
3. Inspect `dist/live.png` at 1920 px and at 830 px (`node build/renderer/review.js` with `build/review/reference.png`), and the Playwright capture `tests/visual/constellation.spec.ts-snapshots/live*.png`.
4. Update `CONTINUITY.md`.
5. Delete the spike script from the scratchpad.
