# Phase 2 — GitHub Integration (design)

Date: 2026-11-06. Status: **approved by the project owner on 2026-11-06** with
the adjustments already incorporated (`dist/live.png` output, three-state value
for private contributions, PRs/reviews as visible activity, compatibility with
the Phase 3 Quick Start / Advanced Setup).

Phase 1 was visually approved by the project owner on 2026-11-06 (CONTINUITY.md
will be updated when this document is approved). The project owner has
confirmed that implementation is delegated to Opus agents or simpler models and
that the main agent reviews; the plan is therefore split into small,
self-contained tasks with explicit review checkpoints.

## 1. Intent and rationale

Phase 1 produces the approved image from a simulated fixture. Phase 2 replaces
that fixture with real statistics obtained from the official GitHub GraphQL API,
keeping the approved design and the PHP → `build/data.json` → TypeScript
renderer → Playwright pipeline intact.

The immediate goal is for the project owner to run the tool on their machine,
with a personal token in `.env`, against their own account, and check that the
figures match what GitHub shows on their profile, including the anonymized
private-contribution counts they already have enabled. The tool must remain
reusable by anyone else with their own token and their own `config`.

## 2. Goals, non-goals and anti-goals

### Goals

1. A `--live` mode that queries GitHub GraphQL and generates `dist/live.png`
   (default path; `dist/constellation.png` remains the committed demo image)
   with real data: daily calendar for the last 12 months, Pull Requests, Code
   Reviews, Active Days, Longest Streak and language ranking.
2. Two scenarios supported by the same code: the authenticated user's own
   account and another person's public account. Only the configured `username`
   changes.
3. Distinguish available metrics from restricted ones and reflect that in the
   image and in the CLI output.
4. `--demo` mode left intact for development and tests.
5. The four minor adjustments approved in the visual review (section 7).
6. Unit tests with a mocked HTTP client, an optional integration test against
   the real API, and updated renderer/visual tests.
7. Documentation of permissions, privacy and limitations.

### Non-goals (later phases or never)

- GitHub Actions, automatic publishing, anything from Phase 3.
- REST API, persistent response cache, offline mode with old data.
- New metrics (commits, issues, stars, language percentages).
- Organization repositories, enterprise accounts, GitHub Enterprise Server.
- Visual redesign beyond the adjustments in section 7.
- Support for proxies, GitHub App tokens or OAuth.

### Anti-goals (failure even if it "works")

- Inventing figures or silently replacing real data with the demo fixture when
  the API fails. An API failure ends with exit 1 and no new image.
- The token appearing in `data.json`, in HTML, in logs, in error messages or in
  process arguments.
- Requesting the `repo` scope or any permission that gives access to private
  code.
- Storing or displaying private repository names, branches, PR titles or any
  internal metadata. The queries do not request repository names.
- Claiming in the image that PRs or reviews include private activity.

## 3. Verified facts about the GitHub API

These facts constrain the design. Source: the GitHub GraphQL reference
(`User.contributionsCollection`, `ContributionsCollection.restrictedContributionsCount`)
and the documentation of the profile setting "Include private contributions on
my profile".

- `contributionsCollection(from, to)` accepts a range of at most one year.
  `contributionCalendar.weeks[].contributionDays[]` returns `date` and
  `contributionCount` per day; the returned weeks may include days outside the
  requested range, which must be filtered out.
- If the account owner has enabled private-contribution visibility, the daily
  calendar includes those counts for **any** authenticated querier, not just the
  owner. That covers the "another user's public account" scenario. The earlier
  claim that only the owner could see them was incorrect.
- `restrictedContributionsCount` is the number of contributions the querier
  cannot see; it is non-zero only when the owner shares their private counts.
  `hasAnyRestrictedContributions` accompanies it as a boolean.
- `totalPullRequestContributions` and `totalPullRequestReviewContributions`
  count only PRs and reviews in repositories the token can see. With a token
  without the `repo` scope, private PRs and reviews do **not** enter those
  totals; they fall within the restricted count. These two metrics are therefore
  "visible activity", not "all activity".
- Recommended minimum token: a classic token with only `read:user`. This is the
  scope the reference cites for including private and internal contributions as
  counts. Fine-grained tokens are not verified for `contributionsCollection`;
  the documentation will mention them as untested.
- The GraphQL API always requires a token, even for public data.
- Rate limit: 5,000 points per hour per token. This tool makes between 2 and
  about 12 requests per run, so no cache is designed.

## 4. Constraints inherited from Phase 1

- Fixed stack: PHP 8.3, Symfony HttpClient 7.4 (already installed, unused),
  PHPUnit 12.5, TypeScript 5.9, Vitest 4.1, Playwright 1.65. No Laravel, React,
  Vue or Tailwind.
- `ContributionCalendar` requires one day for each date of the period, with no
  gaps or duplicates, and at most 366 days. `ContributionMetrics::calculate` and
  `LanguageRanking::calculate` are reused unchanged: the first receives the
  calendar, the second a list of repositories with `visibility`, `isFork`,
  `isArchived` and `languages[{name,color}]`.
- The renderer recalculates `totalContributions`, `activeDays` and
  `longestStreak` from `days` and rejects the file if they do not match. That is
  a free check that is kept.
- `NodeExporter` runs Node without a shell with `--input/--output`; this does
  not change.
- `.gitignore` already ignores `.env`, `.env.*` (except `.env.example`) and
  `config/local.json`. That is the intended place for personal configuration.

### 4.1 Compatibility with Phase 3 (without implementing it)

The project owner has stipulated that, when finished, the tool will have two
modes: **Quick Start** (a reusable GitHub Actions workflow; no PHP or Node
installation, no cloning or forking; using the automatic `GITHUB_TOKEN` when it
suffices and a personal token in Secrets when it does not) and **Advanced
Setup** (local execution with `.env`, as in this phase). None of that is built
now, but Phase 2 must not close the door on it. Constraints that follow:

- The token is always read from the `GITHUB_TOKEN` environment variable; `.env`
  is only a way to populate it locally. In Actions there will be no `.env`.
- Everything that is configured today in `config/*.json` (username, name, role,
  stack, output, colors) must also be able to come through CLI options in
  Phase 3. In this phase it is enough that `Configuration` remains an immutable
  object built from an array, not coupled to the file.
- The CLI must not depend on the working directory or on project-relative paths
  to read `.env` or write the output: `Application` already receives an explicit
  `projectRoot`; this is kept.
- It is not assumed that the token is a classic PAT: the client only requires
  "Bearer <token>". If the automatic Actions `GITHUB_TOKEN` turns out to be
  insufficient for the private counts, that is a Phase 3 finding, and the
  `unknown` state of `privateContributions` covers that case without inventing
  anything.

## 5. Decisions made

Each decision indicates whether the project owner set it or it is a design
choice.

### 5.1 CLI

| Decision | Who |
|---|---|
| `--demo` and `--live` are mutually exclusive flags and one is mandatory. With neither, an error with help. | design |
| In `--live` the queried login is the `config`'s `username`. There is no `--user`: to query another person, another configuration file is used with their `username`, `name`, `role` and `stack`, so that the image never mixes one person's name with another's statistics. | design |
| `config/live.example.json` is added as a template; the project owner copies it to `config/local.json` (already ignored) and runs `php bin/constellation --live --config=config/local.json`. | design |
| The default output in `config/live.example.json` is `dist/live.png` (ignored by `.gitignore`, which keeps only `dist/constellation.png`). This way a real run never overwrites the committed demo image nor ends up published by accident. The project owner can choose another path with `--output` or in their config. | design |
| `name`, `role` and `stack` keep coming from the `config`, not from GitHub. The project owner controls the displayed text. | design |
| At the end the CLI prints a plain-text verification summary: login, period, calendar total, restricted count, PRs, reviews, active days, streak, repositories scanned, top languages and remaining rate-limit points. Never the token. | design |
| Any network, authentication, rate-limit, GraphQL or validation failure ends with exit 1, a message on STDERR and the previous image untouched. There is no fallback to demo. | project owner |

### 5.2 Token and environment

| Decision | Who |
|---|---|
| The token is read from `GITHUB_TOKEN`. In `--live` mode `<root>/.env` is loaded if it exists, without overwriting variables already present in the process. In `--demo` mode nothing is read. | project owner (location) / design (precedence) |
| The dependency `symfony/dotenv ^7.4` is added to parse `.env`. Same family as HttpClient, avoids a home-made parser. Only `Dotenv::parse()` is used: the token is held in an in-memory object and is **never** exported to `$_ENV`, `$_SERVER` or `putenv`. | design |
| `NodeExporter` passes `proc_open` an explicit environment from which `GITHUB_TOKEN` and any variable whose name contains `TOKEN`, `SECRET` or `PASSWORD` are removed, so that the Node/Chromium process does not inherit credentials. | design |
| The token is validated only as "non-empty, with no whitespace or control characters". The prefix is not validated. | design |
| The token travels only in the `Authorization: Bearer` header. Exceptions are built without including headers or request bodies. Tests verify that no error message contains the token. | project owner |
| `.env.example` documents `GITHUB_TOKEN` and the minimum scope `read:user`. | design |

### 5.3 Period

| Decision | Who |
|---|---|
| Rolling period: `end` = today in UTC, `start` = the same day of the previous year plus one day, so that it covers 365 days (366 if it includes 29 February, within the `ContributionCalendar` limit). | design |
| `from` = `start T00:00:00Z`, `to` = `end T23:59:59Z`. Returned days outside `[start, end]` are discarded before building the calendar. | design |
| The image subtitle shows the actual period in the existing format ("Nov 2025 – Nov 2026"). The first month may be partial; the constellation already supports partial weeks. | design |
| The CLI summary and `data.json` record `generatedAt` (ISO 8601 UTC) so the project owner knows when the snapshot was taken. | design |

### 5.4 GraphQL queries

Two queries, both on `user(login:)` so that own and other accounts share code.
`viewer` is not used.

**Query A — contributions** (one request):

```graphql
query($login: String!, $from: DateTime!, $to: DateTime!) {
  rateLimit { remaining resetAt }
  user(login: $login) {
    login
    contributionsCollection(from: $from, to: $to) {
      contributionCalendar {
        totalContributions
        weeks { contributionDays { date contributionCount } }
      }
      totalCommitContributions
      totalIssueContributions
      totalPullRequestContributions
      totalPullRequestReviewContributions
      totalRepositoryContributions
      restrictedContributionsCount
      hasAnyRestrictedContributions
    }
  }
}
```

**Query B — repositories for languages** (paginated, 100 per page):

```graphql
query($login: String!, $after: String) {
  user(login: $login) {
    repositories(
      first: 100, after: $after,
      ownerAffiliations: [OWNER], privacy: PUBLIC, isFork: false,
      orderBy: { field: NAME, direction: ASC }
    ) {
      pageInfo { hasNextPage endCursor }
      nodes {
        isArchived isFork visibility
        languages(first: 20, orderBy: { field: SIZE, direction: DESC }) {
          nodes { name color }
        }
      }
    }
  }
}
```

| Decision | Who |
|---|---|
| `name`, `nameWithOwner`, `description` and repository URLs are not requested. This way there is nothing to filter and nothing that could end up in a file. | project owner (privacy) / design (mechanism) |
| Only repositories with `ownerAffiliations: OWNER`. Organization repositories are left out of the ranking in V1 because their languages do not necessarily represent the ones the user personally uses. Including organizations may become a configurable option in future versions. | project owner |
| `privacy: PUBLIC` and `isFork: false` in the query, and in addition `LanguageRanking` filters `visibility`, `isFork`, `isArchived` again. Double barrier. | design |
| At most 20 pages (2,000 repositories). Beyond that, an explicit error instead of a silently truncated ranking. | design |
| Up to 20 languages per repository (Phase 1 already counts secondary languages). | design |
| Null `user` (nonexistent login) → "user not found" error. Returned `login` different from the requested one (ignoring case) → error. | design |
| GitHub returns `color: null` for languages with no assigned color. `RepositoriesQuery` replaces `null` with the neutral gray `#8b949e` (the one GitHub's own interface uses) before passing the data to `LanguageRanking`, which does not change. The color is presentation, not a statistic; the language count is neither altered nor omitted. | design |

### 5.5 HTTP client and errors

| Decision | Who |
|---|---|
| `POST https://api.github.com/graphql`, headers `Authorization`, `User-Agent: git-constellation`, `Accept: application/json`, 30 s timeout, 5 MB maximum response. | design |
| Retries: up to 2 retries with increasing delay only for transport errors and HTTP 5xx. None for 4xx. | design |
| Error mapping to clear messages: 401 → invalid or missing token; 403/429 with `x-ratelimit-remaining: 0` → rate limit with reset time; other 4xx → message with the code; non-JSON body → format error. | design |
| If the response carries `errors` (even if it also carries `data`), it fails. Partial data is not accepted. The `errors[].message` messages are forwarded to the user; GitHub does not include the token in them. | project owner (no partial data) |
| The client receives `HttpClientInterface` through its constructor so that `MockHttpClient` can be injected in tests. | design |

### 5.6 Mapping to metrics

| Output field | Source | Includes private activity |
|---|---|---|
| `days[]` | daily calendar filtered to the period | yes, as counts, if the owner shares them |
| `metrics.totalContributions` | sum of `days[]` (must match `contributionCalendar.totalContributions`; otherwise error) | yes, same as above |
| `metrics.activeDays`, `metrics.longestStreak` | `ContributionMetrics::calculate` over `days[]` | yes, same as above |
| `metrics.pullRequests` | `totalPullRequestContributions` | no (only repos visible to the token) |
| `metrics.codeReviews` | `totalPullRequestReviewContributions` | no (only repos visible to the token) |
| `languages[]` | `LanguageRanking::calculate` over query B | no (public only) |
| `privateContributions` | three-state value, rule below | — |
| `source.restrictedContributions` | `restrictedContributionsCount` | — |
| `source.visibleTotals` | the five visible totals (commits, issues, PRs, reviews, repositories created) | no |

**Private contributions state (three values).** The project owner asked not to
assume that all activity is public just because `restrictedContributionsCount`
is zero. Query A requests, in addition to the fields already listed,
`totalCommitContributions`, `totalIssueContributions` and
`totalRepositoryContributions`. With that:

- `included`: `restrictedContributionsCount > 0`, or
  `hasAnyRestrictedContributions` is `true`, or
  `contributionCalendar.totalContributions` exceeds the sum of the five visible
  totals. Any one of the three signals is enough.
- `excluded`: the restricted count is 0, `hasAnyRestrictedContributions` is
  `false` **and** the calendar total is exactly equal to the sum of the five
  visible totals. That is, the data contains no count that is not explained by
  visible activity. This is not an assumption: it is an arithmetic check on the
  response.
- `unknown`: any other case (a field is missing, the calendar is smaller than
  the visible sum, or the arithmetic does not add up in any foreseen way).

**Project owner's correction (2026-11-06, takes precedence over the above):**
the arithmetic signal (`calendar > visible sum`) is **not** enough for
`included`. Only `restrictedContributionsCount > 0` or
`hasAnyRestrictedContributions` mark `included`. A calendar larger than the
visible sum with no restricted signal is `unknown`. In addition, a token with
the `repo` scope causes the run to be rejected with an error, not a warning.

Image subtitle according to the state: `included` → "private counts
anonymized"; `excluded` → "public activity only"; `unknown` → "visible activity
only" (neutral text, without claiming it is all activity or only public
activity).

**PRs and Code Reviews.** These are counts of the activity visible to the token
and may exclude private activity. This is reflected in three places: the CLI
summary (line "Pull Requests (visible to token): N"), the `title` attribute of
the two cards in the HTML ("Visible to the token; may exclude private
activity"), and the limitations table in the README and in `docs/phase-2.md`. No
new visible text is added to the cards, out of respect for the approved design.

**Uncertainty still to verify with the real API.** The facts in section 3 come
from documentation, not from a run. One concrete doubt remains for the main
scenario (the owner queries their own account with a `read:user` token without
`repo`): it is not confirmed whether GitHub treats their private contributions
as "restricted" for that token (count > 0) or as accessible (count 0 even though
the calendar includes them). The prior spike (section 6) also checks whether the
calendar's `totalContributions` equals the sum of the five visible totals plus
`restrictedContributionsCount`; if that identity holds, the three-state rule is
exact and is fixed as is. If it does not hold, the discrepancy is documented and
the default state for that case becomes `unknown`. The spike result is recorded
in `docs/phase-2.md`.

### 5.7 `data.json` contract

`schemaVersion: 2` is published. Changes relative to v1:

- `mode`: `"demo" | "live"`.
- `generatedAt`: ISO 8601 UTC string. Present in both modes.
- `source`: object with `provider: "github"`, `login`, `restrictedContributions`
  (integer ≥ 0), `visibleTotals` (object with `commits`, `issues`,
  `pullRequests`, `reviews`, `repositories`, integers ≥ 0) and
  `repositoriesScanned` (integer ≥ 0). In demo, `source` is `null`.
- `privateContributions`: `"included" | "excluded" | "unknown"`. Replaces the
  boolean `privateContributionsIncluded` from v1. In demo it is necessarily
  `"excluded"`; `"included"` and `"unknown"` are accepted only in
  `mode: "live"`.
- Everything else (profile, theme, period, days, metrics, languages) does not
  change.
- The renderer rejects any `schemaVersion` other than 2. `RendererData::fromDemo`
  also emits v2 so that there is a single contract.

`RendererData` gains `fromLive(Configuration, LiveSnapshot): array`. Both
factories share the common assembly.

### 5.8 Code structure

New namespace `src/GitHub/`:

- `GraphQlClient`: sending queries, headers, retries, error mapping.
- `GitHubApiException` (extends `RuntimeException`): the client's only public
  exception, with a `kind` (auth, rateLimit, notFound, transport, response).
- `ContributionsQuery`: text of query A and parsing of its response into
  `ContributionCalendar` + counters.
- `RepositoriesQuery`: text of query B, pagination and parsing into the
  repository list that `LanguageRanking` understands.
- `LiveStatistics`: orchestrates both queries and returns `LiveSnapshot`.

New in `src/Data/`: `LiveSnapshot` (readonly: login, calendar, pullRequests,
codeReviews, restrictedContributions, hasRestricted, languages,
repositoriesScanned, generatedAt) and `Period` (computation of the rolling
period from an injectable `DateTimeImmutable`, for deterministic tests).

New in `src/Config/`: `Environment` (optional `.env` loading, reading and
validation of `GITHUB_TOKEN`).

`src/Cli/Arguments` and `Application` learn `--live`. `Application` builds the
real client only in live mode.

## 6. Tests

Everything is deterministic except the integration test marked as such.

### PHP (`tests/php/`)

- `GitHub/GraphQlClientTest`: Bearer header present, `User-Agent`, 401 →
  `auth` exception, 403/429 with rate limit → `rateLimit` with `resetAt`, 5xx →
  retries and then fails, 4xx does not retry, `errors[]` with `data` → fails,
  non-JSON body → fails, and **no exception message contains the token**.
- `GitHub/ContributionsQueryTest`: weeks with out-of-range days are filtered,
  missing days → error, mismatched `totalContributions` → error, mapping of
  counters and of `restricted*`, `user: null` → notFound, different login →
  error.
- `GitHub/RepositoriesQueryTest`: pagination over 3 pages, page cap,
  archived/private/fork repos discarded, secondary languages counted.
- `GitHub/LiveStatisticsTest`: full composition with `MockHttpClient` and
  synthetic responses → expected `LiveSnapshot`.
- `Data/PeriodTest`: rolling period for a normal date, for 29 February, for 1
  January; always UTC; 365 or 366 days.
- `Data/RendererDataLiveTest`: `fromLive` produces the v2 contract with the key
  order, the three `privateContributions` states from synthetic responses
  (including the arithmetic case with no restricted count), `source` populated;
  `fromDemo` emits v2 with `source: null` and `"excluded"`.
- `Config/EnvironmentTest`: `.env` is loaded, the process environment takes
  priority, empty token or token with whitespace → error, demo mode does not
  read `.env`.
- `Cli/ArgumentsTest`: `--live`, exclusivity with `--demo`, neither → error.
- `Render/NodeExporterTest`: the environment passed to the child process
  contains neither `GITHUB_TOKEN` nor variables with `TOKEN`/`SECRET`/`PASSWORD`.
- `GitHub/RepositoriesQueryTest` (added): a language with `color: null` gets the
  neutral gray and is counted all the same.
- `Integration/GitHubLiveTest`: skipped unless `GITHUB_TOKEN` and
  `GIT_CONSTELLATION_LOGIN` exist in the environment. Checks the full calendar,
  consistency of totals, rate limit > 0. Does not assert specific figures.

The mocked responses live in `tests/php/fixtures/github/` and are synthetic, not
recorded from the project owner's account.

### TypeScript (`tests/ts/`)

- `validation.test.ts`: accepts `mode: "live"` with the three
  `privateContributions` states, rejects `included`/`unknown` in demo, requires
  `schemaVersion: 2`, validates `generatedAt` and `source`.
- `render.test.ts`: no DEMO badge in live, with badge in demo, the three
  subtitles by state, `title` of the PR/Reviews cards, cards without secondary
  descriptions, header without tagline.

### Visual (`tests/visual/`)

- The existing snapshots are updated once for the changes in section 7
  (deliberate action, reported).
- New test: fixture in live mode, no badge, with private-contributions subtitle,
  at 1920 px and at 830 px.

### Prior spike (before implementing)

A disposable, read-only script, outside `src/`, that reads `GITHUB_TOKEN` from
`.env`, runs the extended query A with the five visible totals against the
project owner's account and shows only the aggregate counters (never the token
nor repository names). Its result fixes the `privateContributionsIncluded` rule
of section 5.6 and is recorded in `docs/phase-2.md`. The script is deleted when
done; nothing it produces is kept in the repository.

### Verification of acceptance criterion 3

"Verified" means, in this order:

1. The figures in the CLI summary match exactly a direct GraphQL query (`curl`)
   with the same token, same login and same `from`/`to`. This is reproducible
   and is the main check.
2. A spot check of at least five specific dates: the `count` in `days[]` matches
   the daily tooltip of the public profile's contribution graph. The profile's
   daily counts are exact per date.
3. The profile's annual total ("contributions in the last year") is compared
   only as an order of magnitude: GitHub uses its own window of full weeks,
   different from this tool's rolling 365 days, so it does not have to match and
   the documentation says so.
4. PRs and reviews are compared with the GraphQL totals (point 1), not with the
   profile page, and the documentation makes clear that they exclude private
   activity not visible to the token.

The token will be in `.env` on the project owner's machine, which is where this
session runs; the main agent performs the real run and the comparison, and the
project owner confirms the image. It is documented in `docs/phase-2.md`.

## 7. Approved visual adjustments

The only template and CSS changes allowed. No change to palette, composition,
constellation or cards beyond what is listed.

1. **Bottom cards:** the secondary lines ("Created · simulated", "Submitted ·
   simulated", "In the example period", "Consecutive active days") are removed
   in both modes. What remains is the number and the name. For a `null` value,
   "—" with "Not available" is kept. *(project owner; applying it in both modes
   is a design decision)*
2. **Header:** the line "ACTIVITY • CODE • CONTRIBUTIONS" is removed. At 830 px
   it is illegible. "DEVELOPER CONSTELLATION" is kept. *(project owner asked to
   evaluate it; design decides to remove it)*
3. **Top Languages:** no percentages, as it is. The note "By public repository
   count" is kept and the README explains the criterion: the number of the
   user's own public, non-archived, non-fork repositories in which the language
   appears, including secondary languages; ties broken by name; top 5.
   *(project owner)*
4. **DEMO / SIMULATED DATA badge:** only in `mode: "demo"`. In live it is not
   rendered. The accessibility texts (`<title>`, `aria-label`, `sr-only`
   paragraph) and the languages empty-state text stop saying
   "simulated"/"example" in live. *(project owner)*

The space left by the tagline and the descriptions is not filled with anything
new; the builder adjusts the CSS minimally so the cards are not left with a
visible gap.

## 8. Documentation

- `README.md`: new section "Live mode" (create a classic token with only
  `read:user`, place it in `.env`, copy `config/live.example.json` to
  `config/local.json`, run, read the summary); table of which metrics include
  private activity; language criterion; rolling period; note on unverified
  fine-grained tokens; behavior on failure (never a fallback).
- `docs/phase-2.md`: v2 contract, GraphQL queries, privacy decisions, manual
  verification procedure, known limitations.
- `.env.example` and `config/live.example.json` updated.
- `renderer/README.md`: v2 contract and modes.
- `CONTINUITY.md`: update when closing the phase.

## 9. Acceptance criteria (from the project owner)

1. Image generated with real statistics from the project owner's account.
2. Constellation based on the real calendar, with no randomness.
3. Metrics verified against GitHub (procedure in section 6).
4. Integration tests and statistics-processing tests.
5. Real Playwright capture of the final result.
6. Documentation of the limitations of access to private information.

Phase 3 is not started.

## 10. Left to the builder

- Exact method names, shape of the internal exceptions, format of the help text.
- How to share the common assembly between `fromDemo` and `fromLive`.
- Detail of the retry backoff (within "2 retries, increasing delay, transport/5xx
  only").
- Minimal CSS adjustment after removing the tagline and descriptions.
- Organization of the synthetic test fixtures.
