# Continuity — Git Constellation

Last updated: 2026-11-09

## Current state

- Local repository: `/Users/eduardcherkashyn/develop/git-constellation`. No remote, nothing pushed.
- `main` holds the approved Phase 1 (commit `58caed4`).
- Branch `phase-2-github-integration` holds Phase 2: live GitHub mode, fully implemented, task-reviewed and whole-branch-reviewed (see `docs/superpowers/plans/2026-11-06-phase2-github-integration.md` and the spec next to it). Not merged into `main` yet.
- Phase 2 **code is complete and verified against the owner's real account** (spike done, `docs/phase-2.md` "Real-API check" filled, live image generated at `dist/live.png`). Open:
  1. Owner's visual approval of `dist/live.png` (1920 px and README width).
  2. After approval: merge `phase-2-github-integration` into `main` locally (no push unless asked).
  3. Owner's explicit approval before anything of Phase 3 starts.
- Known, accepted limitation: the API calendar total is about 3% below the profile's "contributions in the last year" (activity in private organization repositories the API does not return to the token). Investigated and documented in `docs/phase-2.md` ("Known differences with the GitHub profile"); the image shows the API figure, never estimates or profile scraping.
- Working method fixed by the owner: implementation delegated to Opus/Sonnet/Haiku subagents, each task reviewed by an independent subagent, the main agent reviews the whole; everything in the repository in English; minimal comments; no unnecessary abstractions; local commits authorized, no push.

## Product and agreed limits

Git Constellation is an open-source CLI that renders a panoramic GitHub activity image (1920 × 840 PNG) for a profile README. No SaaS, no server, no database. Stack: PHP 8.3, Composer, Symfony HttpClient 7.4 and Dotenv 7.4, PHPUnit 12.5, TypeScript 5.9, Playwright 1.65, Vitest 4.1. No Laravel, React, Vue or Tailwind.

The approved visual direction must not change. Phase 2 applied only the four adjustments the owner approved: no `ACTIVITY • CODE • CONTRIBUTIONS` line, no secondary captions under the metric cards, the DEMO badge only in demo mode, and a three-state privacy subtitle.

Future shape (Phase 3, not started; workflow referenced as `@v1` major tags, `@latest`, or exact `@vX.Y.Z`): a Quick Start via a reusable GitHub Actions workflow (no PHP/Node install, no clone, automatic `GITHUB_TOKEN` when sufficient, personal token via Secrets otherwise) and the current Advanced Setup (local run with `.env`). The Phase 2 design keeps the token in an environment variable and the configuration in a JSON file so both can be driven from Actions later.

## Phase 2 behaviour (implemented)

- `--demo` unchanged (fixture, no network). `--live` queries GitHub GraphQL for the login in the config (`config/local.json` by default; template `config/live.example.json`).
- Token: `GITHUB_TOKEN` from the process environment, else `<root>/.env` (parsed, never exported). Classic token with only `read:user`. A token with the `repo` scope is rejected. Fine-grained tokens work but give public-only PR/review counts; classic `read:user` is recommended.
- Period: rolling 365 days ending today UTC (366 when the span contains Feb 29 and ends after it).
- Metrics: daily calendar, total, active days and longest streak include anonymized private counts when the profile owner shares them; Pull Requests and Code Reviews count only token-visible activity; languages from public, non-fork, non-archived repositories the user owns, collaborates on or reaches through an organization, counted by repository presence, top 5, no percentages. For the token owner's own account, totals, PRs and reviews include private activity as counts (spike verified).
- Private-contributions state: `included` on explicit signals only (restricted count > 0, `hasAnyRestrictedContributions`, or the token belongs to the queried account); `excluded` only with no signal and exact arithmetic; otherwise `unknown`. The image subtitle shows only the period; the state lives in `data.json`, the CLI summary and the screen-reader text.
- Failures (auth, rate limit, network, GraphQL errors, malformed data, unknown user, > 2000 repos) exit 1, print `Error:` to STDERR, write nothing, never fall back to demo. The token never appears in output, files, exceptions or the Node child process (environment filtered by name and by value).
- Live output defaults to `dist/live.png` (gitignored); live mode refuses to write `dist/constellation.png`, the committed demo image.
- `build/data.json` is schema v2, shared by demo and live (`mode`, `generatedAt`, `privateContributions`, `source`).

## Commands

```sh
composer install && npm ci && npx playwright install chromium && npm run build
composer run demo                              # demo image → dist/constellation.png
cp config/live.example.json config/local.json  # edit username/name/role/stack
cp .env.example .env                           # set GITHUB_TOKEN
php bin/constellation --live                   # live image → dist/live.png + verification summary
composer test                                  # PHPUnit (integration test skipped without env)
npm test                                       # build + Vitest
npm run test:visual                            # Playwright (snapshots are macOS-only)
node build/renderer/review.js                  # comparison gallery in build/review/
```

Integration test against the real API: `GITHUB_TOKEN=… GIT_CONSTELLATION_LOGIN=<login> ./vendor/bin/phpunit --group integration`.

## Verification already done (2026-11-06, branch head `12df6c7`)

- `composer validate --strict` OK; PHPUnit 153 tests / 353 assertions (1 skipped: integration); Vitest 63; Playwright 8 (snapshots updated once, deliberately, for the approved adjustments; `live-darwin.png` and `live-readme-830-darwin.png` added).
- Per-task independent reviews: 10 tasks, 2 needed one fix round each (Tasks 5 and 9). Whole-branch review by Opus: "with fixes"; one fix wave of nine items applied and re-reviewed clean. Two cosmetic findings parked (text-only path comparison in the demo-image guard; NotFound message without prefix).
- Real GitHub API: **not exercised yet** (no token in the session).

## Review artifacts

- Demo image: `dist/constellation.png` (tracked). Live image: `dist/live.png` (ignored, not generated yet).
- Visual baselines: `tests/visual/constellation.spec.ts-snapshots/`.
- Comparison gallery and reference: `build/review/` (ignored; keep `build/review/reference.png` safe, it is the owner's reference and `build/` is ephemeral).
- Design and plan: `docs/superpowers/specs/2026-11-06-phase2-github-integration-design.md`, `docs/superpowers/plans/2026-11-06-phase2-github-integration.md`. Phase docs: `docs/phase-1.md`, `docs/phase-2.md`.

## How to resume in a new session

1. Read this file, `README.md`, `docs/phase-2.md`; check `git status --short --branch` and `git log --oneline`.
2. If `.env` exists at the root: run the read-only spike (a throwaway script that prints only aggregated counters — never the token), fill the "Real-API check" in `docs/phase-2.md`, adjust `ContributionsQuery::assertCalendarTotal` only if GitHub's total matches neither sum, then run `php bin/constellation --live` and the manual verification steps.
3. Show the owner `dist/live.png` (1920 px and 830 px) and the verification summary; wait for explicit approval.
4. Only after approval: merge `phase-2-github-integration` into `main` locally (no push unless asked) and start Phase 3 (GitHub Actions Quick Start), respecting the privacy rules above.

Short prompt for a new session: "Read `CONTINUITY.md` at the root and continue Git Constellation from that state. Phase 2 code is complete on branch `phase-2-github-integration`; the real-API spike and run are pending my `.env`. Delegate implementation to Opus or lower models and review the result yourself. Everything in the repo in English. Do not start Phase 3 without my explicit approval."
