# Phase 2: live GitHub statistics

## Goal

Add a `--live` mode that fetches real statistics for one GitHub user over a rolling one-year period, using the GitHub GraphQL API and a read-only token, and renders them with the same offline renderer as the demo. Demo mode is unchanged and still needs no credentials.

Phase 2 does not publish anything and does not add automation. Both are Phase 3 topics (see "Planned compatibility with Quick Start").

## Data contract v2

PHP writes `build/data.json`; the TypeScript renderer validates and consumes it. `schemaVersion` is `2`. Demo output uses the same schema with `mode: "demo"`, `privateContributions: "excluded"` and `source: null`.

Live example (`days` is shortened here; the real array contains every day of the period, ordered and contiguous):

```json
{
  "schemaVersion": 2,
  "mode": "live",
  "generatedAt": "2026-11-06T12:00:00Z",
  "profile": {
    "username": "octo-sample",
    "name": "Octo Sample",
    "role": "Backend Developer",
    "stack": "PHP / TypeScript"
  },
  "theme": { "cyan": "#30e8ef", "green": "#52f5a0" },
  "period": { "start": "2025-11-07", "end": "2026-11-06" },
  "days": [
    { "date": "2025-11-07", "count": 3 },
    { "date": "2025-11-08", "count": 0 }
  ],
  "metrics": {
    "totalContributions": 1351,
    "activeDays": 327,
    "longestStreak": 20,
    "pullRequests": 136,
    "codeReviews": 84
  },
  "languages": [
    { "name": "PHP", "repositories": 8, "color": "#4f5d95" }
  ],
  "languagesSource": "ranking",
  "privateContributions": "included",
  "source": {
    "provider": "github",
    "login": "octo-sample",
    "restrictedContributions": 7,
    "ownAccount": false,
    "visibleTotals": {
      "commits": 900,
      "issues": 12,
      "pullRequests": 136,
      "reviews": 84,
      "repositories": 5
    },
    "repositoriesScanned": 31
  }
}
```

The values are illustrative.

Rules enforced by `renderer/validation.ts`:

- `mode` is `demo` or `live`. Demo requires `privateContributions: "excluded"` and `source: null`. Live requires a `source` object.
- `days` covers every date of `period` inclusively, ordered, without duplicates; the period has 1 to 366 days.
- `metrics.totalContributions`, `activeDays` and `longestStreak` must agree with `days`.
- `metrics.pullRequests` and `metrics.codeReviews` are non-negative integers or `null`.
- `languagesSource` is `ranking` or `manual`, right after `languages`. `languages` has at most five entries, no duplicates and a six-digit hex colour per entry. With `ranking`, every `repositories` is an integer of at least 1; with `manual` the list has 1 to 5 entries and every `repositories` is `null`.
- `generatedAt` is an ISO 8601 UTC timestamp.

`source` carries provenance only: it never contains repository names, branch names, commit messages or pull request titles.

## GraphQL queries

Both queries are defined as constants in the PHP source and are reproduced verbatim.

### Query A: contributions (`src/GitHub/ContributionsQuery.php`)

```graphql
query($login: String!, $from: DateTime!, $to: DateTime!) {
  rateLimit { remaining resetAt }
  viewer { login }
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

Variables: `login`, `from` (`<period start>T00:00:00Z`) and `to` (`<period end>T23:59:59Z`).

`viewer.login` identifies the token owner. When it equals the requested login (case-insensitively), the run is for the token owner's own account (`source.ownAccount`).

The client keeps only the days inside the period (it tolerates padded week days, although the spike saw none), then requires every date of the period to be present exactly once. The returned login must match the requested one case-insensitively.

### Query B: repositories (`src/GitHub/RepositoriesQuery.php`)

```graphql
query($login: String!, $after: String) {
  user(login: $login) {
    repositories(
      first: 100, after: $after,
      ownerAffiliations: [OWNER, COLLABORATOR, ORGANIZATION_MEMBER], privacy: PUBLIC, isFork: false,
      orderBy: { field: NAME, direction: ASC }
    ) {
      pageInfo { hasNextPage endCursor }
      nodes {
        isArchived isFork visibility
        languages(first: 20, orderBy: { field: SIZE, direction: DESC }) {
          edges { size node { name color } }
        }
      }
    }
  }
}
```

It pages 100 repositories at a time, up to 20 pages. A user with more than 2000 eligible public repositories is rejected instead of ranked on a partial list. Each language edge carries its size in bytes, which only breaks ties in the ranking and is not written to `data.json`. A language with no colour from GitHub gets `#8b949e`.

## Permissions and token

- Create a **classic personal access token** with **only** the `read:user` scope. This is the verified configuration.
- A token that reports the `repo` scope is rejected with an error before the repositories query runs. The scope is not needed, and it would expose private pull request and review activity to the tool.
- **Fine-grained tokens are verified with caveats.** A fine-grained token (resource owner: the user, public repositories read-only) returns the same calendar total, but private activity moves into `restrictedContributionsCount` and the Pull Request and Code Review counts are public only. Use a classic token to include private pull requests and reviews as counts.
- The token is read from `GITHUB_TOKEN` in the process environment; if that is empty or unset, from `<project root>/.env`. The process environment wins.
- The `.env` file is parsed, not loaded: the token is never exported into the PHP process environment.
- The Node renderer never receives the token. The child process environment is filtered of every variable whose name contains `TOKEN`, `SECRET` or `PASSWORD`.
- The token is not written to `build/data.json`, the PNG or the HTML, and is not accepted as a command-line argument.
- The CLI summary prints the scopes GitHub reports for the token (`Token scopes:`), or `(not reported)` when the header is absent.

## What includes private activity

| Item | Token owner's own account | Another account |
| --- | --- | --- |
| Daily calendar, total contributions, active days, longest streak | Include private activity as counts only | Include anonymized private counts only if that person shares them (restricted count) |
| Pull Requests | Include private activity as counts | Public only |
| Code Reviews | Include private activity as counts | Public only |
| Languages | No. Public, non-fork, non-archived repositories the user owns, collaborates on, or can access through an organization membership | Same |

The tool never reads private code, repository names, branches, commit messages or pull request titles.

## Private contributions state

`privateContributions` has three values, decided by `PrivateContributions::determine`:

| State | Rule | Screen-reader sentence |
| --- | --- | --- |
| `included` | `restrictedContributionsCount > 0`, or `hasAnyRestrictedContributions` is true, or the account is the token owner's own (`viewer.login` equals the requested login) | Totals include private activity as anonymized counts. |
| `excluded` | None of the above and the in-period calendar total equals the sum of the five visible totals (commits, issues, pull requests, reviews, repositories) | Totals cover public activity only. |
| `unknown` | Anything else | Totals cover activity visible to the token. |

For the token owner's own account GitHub folds private activity into the normal totals and keeps the restricted count at 0 (spike fact 4), so the own-account signal is explicit. The restricted count is the signal only when the viewer is someone else. `excluded` stays strict: it requires equality.

The image subtitle shows only the period. The state is exposed in `data.json` (`privateContributions`, `source.ownAccount`), in the CLI summary (`Private contributions: <state> (restricted count: <n>, own account: yes|no)`) and in the screen-reader text. Demo mode always states public activity only.

### Real-API check

Result (2026-11-06), verified against the owner's account with a classic `read:user` token:

1. `contributionsCollection(from, to)` returned exactly the requested range: 365 days from `from` to `to`, no padded week days. `contributionCalendar.totalContributions` equalled the sum of the returned days.
2. A 366-day span (`2023-03-02T00:00:00Z` to `2024-03-01T23:59:59Z`) was accepted and returned 366 days. A span slightly longer than one year was also accepted without error.
3. `viewer { contributionsCollection }` and `user(login: <own login>) { contributionsCollection }` returned identical totals.
4. For the token owner's own account, `restrictedContributionsCount` was 0 and `hasAnyRestrictedContributions` was false, yet the totals included private activity: 730 commits, 246 pull requests and 270 reviews in total, while the per-repository breakdown (which only lists repositories the token can access) showed 43 commits in 2 public repositories and no public PRs or reviews. When the viewer is the account owner, GitHub folds private activity into the normal totals (as counts only, no repository data) instead of the restricted count. The restricted count is the signal only when the viewer is someone else.
5. The calendar total (1253) exceeded the sum of the five visible totals (1252) by 1; the cause is unknown (a contribution type not covered by the five totals). The `excluded` rule therefore stays strict (equality required).

`ContributionsQuery` still accepts `totalContributions` if it equals either the sum of all returned days or the sum of the in-period days. The in-period sum feeds the `excluded`/`unknown` rule, since the five visible totals cover the same window. Checklist:

- [x] Record which of the two sums `totalContributions` equals: the returned days, which are exactly the period (no padding).
- [x] Record whether a 366-day span is accepted by `contributionsCollection`: yes (verified).

## Rolling period

The period ends today in UTC and starts one year earlier plus one day. That is 365 days, or 366 when the period ends after February 29 and starts before it (a period ending on February 29 itself has 365 days, because `sub('P1Y')` on that date overflows to March 1). `Period::trailingYear` computes it from the current UTC date; `from` is the start at `00:00:00Z` and `to` is the end at `23:59:59Z`.

This is not the window GitHub uses for the profile page, which is week-aligned. See "Manual verification".

## Language criterion

Each language is counted once per eligible repository in which it appears, secondary languages included. Languages are ordered by repository count descending, then by total size in bytes across the eligible repositories descending, then by name ascending, and the top 5 are kept. The criterion is the number of public, non-fork, non-archived repositories the user owns, collaborates on, or can access through an organization membership, in which the language appears. The image shows no percentages and no byte sizes. The ranking measures presence in repositories, not experience or proficiency. Languages without a GitHub colour use `#8b949e`.

Non-programming languages are excluded by default: CSS, SCSS, Sass, Less, Stylus, HTML, Markdown, MDX, Dockerfile, Makefile, Mako, Jinja, Twig, Blade, Handlebars, Mustache, Pug, XML, JSON, YAML, TOML, Text and Procfile. GitHub's GraphQL `Language` object does not expose Linguist's language type, so the exclusion is a list of names, compared case-insensitively. The optional `excludeLanguages` config key (a list of names) replaces the default list; `[]` disables exclusions. Demo fixture repositories carry no sizes, so a missing size counts as 0.

### Manual language list

The optional `languages` config key (1 to 5 entries, each a name or `{ "name": ..., "color": "#rrggbb" }`, names unique ignoring case) replaces the ranking in demo and live modes. The order is kept. A colour GitHub reported (live) or the fixture gave (demo) wins over the configured one; without either, `#8b949e`. The entries have `repositories: null`, `languagesSource` is `manual`, the image footnote reads `Selected by the developer`, and the CLI line reads `Top Languages (selected): PHP, TypeScript`.

Eligible repositories are public, not forks and not archived, and the user owns them, collaborates on them or can reach them through an organization membership. Query B filters affiliation, public and non-fork; the ranking additionally skips archived ones.

## Errors and no fallback

Any of these ends the run with exit code 1, prints `Error: ...` to STDERR, writes nothing and never falls back to demo data:

- missing or malformed token, or a rejected token (HTTP 401);
- a token with the `repo` scope;
- rate limit exhaustion;
- network failure or timeout, after bounded retries;
- GraphQL errors in the response;
- malformed or incomplete data, including a calendar total that does not match its daily counts;
- user not found, or a different user returned;
- more than 2000 eligible public repositories.

`build/data.json` is written only after the whole fetch succeeded, so a failed run never leaves a half-valid file. The summary is printed after `build/data.json` is written and before the renderer runs; if rendering then fails, the error is reported and the PNG is not produced.

## Output

- Live mode writes `dist/live.png` (the `output` of `config/live.example.json`). `dist/*` is gitignored except the demo image.
- Demo mode keeps writing `dist/constellation.png`.
- `--output` overrides either default. Relative paths resolve from the project root.
- `build/data.json` is the intermediate contract described above.

## Manual verification

Run after `php bin/constellation --live --config=config/local.json`. Use the same token, login, `from` and `to` as the run (`from` is the Period start at `T00:00:00Z`, `to` is the Period end at `T23:59:59Z`).

1. **Compare the summary with a direct GraphQL query.** The token is read from the environment variable and never typed inline:

   ```sh
   curl -s https://api.github.com/graphql \
     -H "Authorization: bearer $GITHUB_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"query":"query($login:String!,$from:DateTime!,$to:DateTime!){viewer{login} user(login:$login){contributionsCollection(from:$from,to:$to){contributionCalendar{totalContributions}totalCommitContributions totalIssueContributions totalPullRequestContributions totalPullRequestReviewContributions totalRepositoryContributions restrictedContributionsCount hasAnyRestrictedContributions}}}","variables":{"login":"YOUR_LOGIN","from":"2025-11-07T00:00:00Z","to":"2026-11-06T23:59:59Z"}}'
   ```

   The summary's `Contributions (calendar)` is the in-period sum of the daily counts. GitHub's `totalContributions` equals that sum (the spike saw no padded days). The pull request and review totals, `restrictedContributionsCount` and the private state must match the summary exactly.
2. **Spot-check at least five dates.** Pick five dates from `build/data.json` (include busy days, an empty day and a period boundary) and compare each count with the daily tooltip on the public profile contribution graph.
3. **Treat "contributions in the last year" on the profile as an order-of-magnitude check only.** GitHub uses its own week-aligned window, so the number differs from a rolling 365-day period.
4. **Compare Pull Requests and Code Reviews against the GraphQL totals**, not the profile page. They count what the token can see.

## Known limitations

- For another account, Pull Requests and Code Reviews count only public activity; for the token owner's own account they include private activity as counts.
- Languages ignore private repositories, forks and archived repositories.
- More than 2000 eligible public repositories are rejected.
- Fine-grained tokens work but report Pull Requests and Code Reviews as public only (see Permissions and token).
- `privateContributions` can be `unknown`; the screen-reader text then says the totals cover activity visible to the token.
- The period is a rolling UTC window and does not match the week-aligned profile figure.
- The visual baselines in `tests/visual` are macOS and Chromium only.
- The calendar total can exceed the sum of the five visible totals by a small amount for unknown reasons (spike fact 5), which keeps `excluded` strict.

## Known differences with the GitHub profile

- The profile's yearly figure exceeded the API calendar by a stable amount over several days. The extra days were weekdays spread over nine months, one to three contributions per day, and the public profile labels them "contributions in private repositories".
- Ruled out: the date window (querying without `from`/`to`, GitHub's default window, returns the same total), `viewer` vs `user(login:)` (identical), SAML SSO (no `X-GitHub-SSO` response header) and public commits (counted identically by both).
- A fine-grained token (resource owner: the user, public repositories read-only) returned the same calendar total; it moved private activity into `restrictedContributionsCount` and reported public-only PR and review counts.
- Likely cause: activity in private organization repositories that GitHub counts for the profile but does not expose through the API to the user's token (for example repositories the user no longer has access to, or organizations that restrict personal access tokens). Not fixable in this tool; an organization owner could try allowing personal access tokens.
- Decision: the image shows the API figure. No scraping of the profile HTML (not an official API) and no estimates.

## Planned compatibility with Quick Start

A reusable GitHub Actions workflow is planned for Phase 3. Nothing of it exists yet: there is no workflow file, no publishing step and no scheduled run. The current design keeps the token in an environment variable and the configuration in a JSON file so that it can be driven from Actions later.

## Verification commands

```sh
composer validate --strict
./vendor/bin/phpunit
./vendor/bin/phpunit --group integration   # skipped unless GITHUB_TOKEN and GIT_CONSTELLATION_LOGIN are set
npm test
npm run test:visual
```

The integration test calls the real API and asserts only structure: 365 or 366 days, calendar bounds equal to the period, a positive rate limit and a valid private contributions state. It asserts no concrete numbers.

With `GITHUB_TOKEN` already exported in the shell:

```sh
GIT_CONSTELLATION_LOGIN=your-login ./vendor/bin/phpunit --group integration
```
