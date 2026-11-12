# Continuity — Git Constellation

Last updated: 2026-11-12 (v1.0.0 released)

## Current state

- Local repository: `/Users/eduardcherkashyn/develop/git-constellation`; public at https://github.com/Camiiilotaba/git-constellation. `main` and `develop` are pushed. Released as `v1.0.0` (2026-11-12, main at 036fc4f), with `v1` and `latest` on the same commit and a GitHub release. CI (`.github/workflows/ci.yml`) installs Chromium for the export test and is green on both branches.
- Branching model (owner, 2026-11-09): `main` holds released states only; `develop` is the integration branch; work happens on `feature/*` branches merged into `develop` with `--no-ff`; `develop` is merged into `main` with `--no-ff` when a phase is approved.
- Phase 1 and Phase 2 are approved by the owner and merged (`phase-2-github-integration` → `develop` → `main`).
- Phase 3 (GitHub Actions Quick Start) is merged into `develop` and verified with real runs in `Camiiilotaba/Camiiilotaba` (first run 2026-11-09): checkout with `persist-credentials: false`, render, publish to the orphan `constellation` branch; scheduled runs every 6 hours succeeded 2026-11-10 to 2026-11-12, about 45 seconds each. The profile workflow references `@v1`; a manual run on the tag succeeded on 2026-11-12 and published both files. Design and checklist: `docs/phase-3.md`.
- Spike answers: with the automatic `GITHUB_TOKEN`, `viewer { login }` resolves and the token is not treated as the owner's account; `contributionsCollection` and the repositories query are readable; no `X-OAuth-Scopes`; private contributions `included` through the restricted count (1401, 2026-11-09); PRs and reviews count public activity only; calendar total 1495. With the classic `read:user` token stored as `CONSTELLATION_TOKEN` (used now): PRs and reviews include private activity (255 and 285, total 1534 on 2026-11-12); on 2026-11-09 the total was 1448 versus 1495 with the automatic token. The pinned SHAs, the per-command extra-header push (including creating the branch) and `ls-remote --symref` work. raw.githubusercontent.com caches a 404 for a few minutes if the image is requested before the branch exists.
- Still open: whether bot pushes to the non-default `constellation` branch count as activity for GitHub's 60-day scheduled-workflow rule.
- Phase 4 (animated SVG) is merged into `develop` and verified on GitHub (2026-11-12): the workflow published `constellation.svg` (331,975 bytes) next to the PNG, served as `image/svg+xml` with the restrictive CSP; the profile README links it and it animates in Chromium, Firefox and WebKit. The README hero image links the SVG. Not verified: whether a real OS reduced-motion setting reaches an SVG shown through `<img>` (an emulated one does not in Chromium); the at-rest invisibility is the guarantee. Layout change in the static image too: the second `</>` mark was removed. Details: `docs/phase-4.md`.
- Known, accepted limitation: the API total is a few percent below the profile's figure (activity in private organization repositories the API does not return). Documented in `docs/phase-2.md`; the image shows the API figure, never estimates or profile scraping.
- Working method fixed by the owner: implementation delegated to Opus/Sonnet/Haiku subagents, each change reviewed by an independent subagent, the main agent reviews the whole; everything in the repository in English; minimal comments; no unnecessary abstractions; local commits authorized; push, publishing, tags and anything touching `Camiiilotaba/Camiiilotaba` need explicit confirmation right before.

## Product and agreed limits

Git Constellation is an open-source CLI that renders a panoramic GitHub activity image (1920 × 840 PNG) for a profile README. No SaaS, no server, no database. Stack: PHP 8.3, Composer, Symfony HttpClient 7.4 and Dotenv 7.4, PHPUnit 12.5, TypeScript 5.9, Playwright 1.65, Vitest 4.1. No Laravel, React, Vue or Tailwind.

The approved visual direction must not change. Phase 2 applied only the four adjustments the owner approved: no `ACTIVITY • CODE • CONTRIBUTIONS` line, no secondary captions under the metric cards, the DEMO badge only in demo mode, and a three-state privacy subtitle.

Phase 3 shape (implemented as a composite action `action.yml`, not a reusable workflow; referenced as `@v1` major tags, `@latest`, or exact `@vX.Y.Z`): a Quick Start via a reusable GitHub Actions workflow (no PHP/Node install, no clone, automatic `GITHUB_TOKEN` when sufficient, personal token via Secrets otherwise) and the current Advanced Setup (local run with `.env`). The Phase 2 design keeps the token in an environment variable and the configuration in a JSON file so both can be driven from Actions later.

- Phase 3 decisions already taken by the owner (2026-11-09): scheduled workflow every 6 hours (configurable) plus a manual "Run workflow" button; the image is stored on a separate branch (e.g. `constellation`) whose single commit is replaced on each run, so `main` gets no bot commits; the profile README links the image from that branch; storing on `main` (one commit per change) may be offered as an option.

## Phase 2 behaviour (implemented)

- `--demo` unchanged (fixture, no network). `--live` queries GitHub GraphQL for the login in the config (`config/local.json` by default; template `config/live.example.json`).
- Token: `GITHUB_TOKEN` from the process environment, else `<root>/.env` (parsed, never exported). Classic token with only `read:user`. A token with the `repo` scope is rejected. Fine-grained tokens work but give public-only PR/review counts; classic `read:user` is recommended.
- Period (config `period`): `all-time` by default — one `contributionYears` query plus one query per calendar year since the first contribution; subtitle `Since <Mon YYYY>`; monthly constellation up to the last complete month (the month in progress counts in totals, is not drawn); year labels at each January, none for a partial first year. `last-year` keeps the rolling 365/366-day weekly view.
- Metrics: daily calendar, total, active days and longest streak include anonymized private counts when the profile owner shares them; Pull Requests and Code Reviews count only token-visible activity; languages from public, non-fork, non-archived repositories the user owns, collaborates on or reaches through an organization, non-programming languages excluded (`excludeLanguages`), ranked by repository count then code size, top 5, no percentages; or a manual `languages` list in the config (the owner uses PHP, TypeScript, JavaScript, Dart, SQL), shown without footnote. For the token owner's own account, totals, PRs and reviews include private activity as counts (spike verified).
- Private-contributions state: `included` on explicit signals only (restricted count > 0, `hasAnyRestrictedContributions`, or the token belongs to the queried account); `excluded` only with no signal and exact arithmetic; otherwise `unknown`. The image subtitle shows only the period; the state lives in `data.json`, the CLI summary and the screen-reader text.
- Failures (auth, rate limit, network, GraphQL errors, malformed data, unknown user, > 2000 repos) exit 1, print `Error:` to STDERR, write nothing, never fall back to demo. The token never appears in output, files, exceptions or the Node child process (environment filtered by name and by value).
- Live output defaults to `dist/live.png` (gitignored); live mode refuses to write `dist/constellation.png`, the committed demo image.
- `build/data.json` is schema v3, shared by demo and live (`mode`, `generatedAt`, `period.kind`/`granularity`, `privateContributions`, `languagesSource`, `source`).
- Background figure redrawn 2026-11-09 (owner chose variant C3: leaning forward, readable laptop).

## Commands

```sh
composer install && npm ci && npx playwright install chromium && npm run build
composer run demo                              # demo image → dist/constellation.png and dist/constellation.svg
cp config/live.example.json config/local.json  # edit username/name/role/stack
cp .env.example .env                           # set GITHUB_TOKEN
php bin/constellation --live                   # live image → dist/live.png and dist/live.svg + verification summary
composer test                                  # PHPUnit (integration test skipped without env)
npm test                                       # build + Vitest
npm run test:visual                            # Playwright (snapshots are macOS-only)
node build/renderer/review.js                  # comparison gallery in build/review/
```

Integration test against the real API: `GITHUB_TOKEN=… GIT_CONSTELLATION_LOGIN=<login> ./vendor/bin/phpunit --group integration`.

## Verification (2026-11-12)

- Local: Vitest 97, action checks 47, PHPUnit 225 (1 skipped: integration), Playwright 12. CI is green on `main` and `develop`. The publish test gives every run its own commit date: two runs in the same second recreated an earlier orphan commit byte for byte and the push from the shallow clone was rejected, which made CI fail intermittently.
- Real runs: Phase 2 API run (2026-11-09) all-time since 2023-10-23, 1448 contributions, 250 PRs, 274 reviews, 264 active days, streak 10, private `included` (own account). Phase 3 and 4 runs on the profile workflow as described under Current state.
- Independent reviews of every change; parked minors are listed in `.superpowers/sdd/*/` ledgers (gitignored).

## Review artifacts

- Demo image: `dist/constellation.png` (tracked). Live image: `dist/live.png` (ignored).
- Visual baselines: `tests/visual/constellation.spec.ts-snapshots/`.
- Comparison gallery and reference: `build/review/` (ignored; keep `build/review/reference.png` safe, it is the owner's reference and `build/` is ephemeral).
- Design and plan: `docs/superpowers/specs/2026-11-06-phase2-github-integration-design.md`, `docs/superpowers/plans/2026-11-06-phase2-github-integration.md`. Phase docs: `docs/phase-1.md`, `docs/phase-2.md`, `docs/phase-3.md`, `docs/phase-4.md`.

## How to resume in a new session

1. Read this file, `README.md`, `docs/phase-2.md`; check `git status --short --branch` and `git log --oneline --graph -15`.
2. No phase is in progress. New work goes on a `feature/*` branch from `develop`; a release follows `docs/releasing.md` (new patch or minor tag, then move `v1` and `latest`). Ask before any push, tag, publish, or change to `Camiiilotaba/Camiiilotaba`.
