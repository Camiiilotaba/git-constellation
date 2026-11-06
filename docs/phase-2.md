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
  "privateContributions": "included",
  "source": {
    "provider": "github",
    "login": "octo-sample",
    "restrictedContributions": 7,
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
- `languages` has at most five entries, no duplicates, each with at least one repository and a six-digit hex colour.
- `generatedAt` is an ISO 8601 UTC timestamp.

`source` carries provenance only: it never contains repository names, branch names, commit messages or pull request titles.

## GraphQL queries

Both queries are defined as constants in the PHP source and are reproduced verbatim.

### Query A: contributions (`src/GitHub/ContributionsQuery.php`)

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

Variables: `login`, `from` (`<period start>T00:00:00Z`) and `to` (`<period end>T23:59:59Z`).

GitHub pads the calendar to whole weeks, so it returns days outside the period. The client keeps only the days inside the period, then requires every date of the period to be present exactly once. The returned login must match the requested one case-insensitively.

### Query B: repositories (`src/GitHub/RepositoriesQuery.php`)

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

It pages 100 repositories at a time, up to 20 pages. A user with more than 2000 eligible public repositories is rejected instead of ranked on a partial list. A language with no colour from GitHub gets `#8b949e`.

## Permissions and token

- Create a **classic personal access token** with **only** the `read:user` scope. This is the verified configuration.
- A token that reports the `repo` scope is rejected with an error before the repositories query runs. The scope is not needed, and it would expose private pull request and review activity to the tool.
- **Fine-grained tokens are unverified.** They may work for public data, but this project has not tested them and does not document a permission set for them.
- The token is read from `GITHUB_TOKEN` in the process environment; if that is empty or unset, from `<project root>/.env`. The process environment wins.
- The `.env` file is parsed, not loaded: the token is never exported into the PHP process environment.
- The Node renderer never receives the token. The child process environment is filtered of every variable whose name contains `TOKEN`, `SECRET` or `PASSWORD`.
- The token is not written to `build/data.json`, the PNG or the HTML, and is not accepted as a command-line argument.
- The CLI summary prints the scopes GitHub reports for the token (`Token scopes:`), or `(not reported)` when the header is absent.

## What includes private activity

| Item | Includes private activity |
| --- | --- |
| Daily calendar, total contributions, active days, longest streak | Yes, as anonymized counts, when the profile owner shares private contributions on their profile |
| Pull Requests | Only activity visible to the token; may exclude private activity |
| Code Reviews | Only activity visible to the token; may exclude private activity |
| Languages | No. Public, owned, non-fork, non-archived repositories only; organization repositories are excluded in this version |

The tool never reads private code, repository names, branches, commit messages or pull request titles.

## Private contributions state

`privateContributions` has three values, decided by `PrivateContributions::determine`:

| State | Rule | Subtitle in the image |
| --- | --- | --- |
| `included` | `restrictedContributionsCount > 0` or `hasAnyRestrictedContributions` is true | `private counts anonymized` |
| `excluded` | No restricted signal and the calendar total equals the sum of the five visible totals (commits, issues, pull requests, reviews, repositories) | `public activity only` |
| `unknown` | Anything else | `visible activity only` |

`included` is claimed only on an explicit GitHub signal. It is never inferred from arithmetic alone. Demo mode always renders `public activity only`.

### Real-API check

Result: pending (to be filled by the maintainer after the read-only spike).

This validates one assumption: that `contributionCalendar.totalContributions` equals the sum of all returned days, including the padded days outside the period, as `ContributionsQuery` currently asserts. If a real response disagrees, the sum in `assertCalendarTotal` must change.

## Rolling period

The period ends today in UTC and starts one year earlier plus one day. That is 365 days, or 366 when the span contains February 29. `Period::trailingYear` computes it from the current UTC date; `from` is the start at `00:00:00Z` and `to` is the end at `23:59:59Z`.

This is not the window GitHub uses for the profile page, which is week-aligned. See "Manual verification".

## Language criterion

Each language is counted once per eligible repository in which it appears, secondary languages included. Languages are ordered by repository count descending, ties by name ascending, and the top 5 are kept. The image shows no percentages and no byte sizes. The ranking measures presence in repositories, not experience or proficiency. Languages without a GitHub colour use `#8b949e`.

Eligible repositories are public, owned by the user, not forks and not archived. Query B already filters public, owned and non-fork repositories; the ranking additionally skips archived ones.

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
     -d '{"query":"query($login:String!,$from:DateTime!,$to:DateTime!){user(login:$login){contributionsCollection(from:$from,to:$to){contributionCalendar{totalContributions}totalCommitContributions totalIssueContributions totalPullRequestContributions totalPullRequestReviewContributions totalRepositoryContributions restrictedContributionsCount hasAnyRestrictedContributions}}}","variables":{"login":"YOUR_LOGIN","from":"2025-11-07T00:00:00Z","to":"2026-11-06T23:59:59Z"}}'
   ```

   `totalContributions` should match the summary's `Contributions (calendar)`; if it differs, that is the question the Real-API check above is meant to settle. The pull request and review totals, `restrictedContributionsCount` and the private state must match the summary exactly.
2. **Spot-check at least five dates.** Pick five dates from `build/data.json` (include busy days, an empty day and a period boundary) and compare each count with the daily tooltip on the public profile contribution graph.
3. **Treat "contributions in the last year" on the profile as an order-of-magnitude check only.** GitHub uses its own week-aligned window, so the number differs from a rolling 365-day period.
4. **Compare Pull Requests and Code Reviews against the GraphQL totals**, not the profile page. They count what the token can see.

## Known limitations

- Pull Requests and Code Reviews count only activity visible to the token and may exclude private activity.
- Languages ignore organization repositories, private repositories, forks and archived repositories.
- More than 2000 eligible public repositories are rejected.
- Fine-grained tokens are unverified.
- `privateContributions` can be `unknown`; the image then says `visible activity only`.
- The period is a rolling UTC window and does not match the week-aligned profile figure.
- The visual baselines in `tests/visual` are macOS and Chromium only.
- The real-API check above is not filled in yet.

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
