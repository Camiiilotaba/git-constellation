# Phase 3: GitHub Actions Quick Start

## Goal

Any GitHub user shows a live, auto-updating constellation in their profile README by adding one workflow file to their profile repository (`<login>/<login>`) and one `<img>` line. No fork, clone or local install.

## Design

- **Composite action, not a reusable workflow.** A reusable workflow cannot reliably know its own ref, so it could not check out its own code at the `@vX.Y.Z` the user pinned; a composite action receives its code at the pinned ref in `github.action_path`.
- The user's workflow (`examples/profile-workflow.yml`) runs `actions/checkout` (`persist-credentials: false`), then `Camiiilotaba/git-constellation@v1`. Triggers: `schedule` (`0 */6 * * *`) and `workflow_dispatch`. `permissions: contents: write`, and a `concurrency` group so two runs never publish at once.
- `action.yml` steps:
  1. Check the stats token is not empty, then runtimes: PHP 8.3+ and Composer must be on the runner (ubuntu-24.04 ships them), otherwise the job fails with a clear error. Node.js `^22.12 || >=24` is used if present; otherwise `actions/setup-node` (pinned by commit SHA) installs Node 24.
  2. `composer install --no-dev` inside the action path.
  3. `scripts/action/config.php` writes `$RUNNER_TEMP/git-constellation/config.json` from the inputs (`GitConstellation\Config\ActionInputs`): the optional `config` file from the caller's checkout is the base, non-empty inputs override its keys, `username` falls back to the repository owner, `output` is dropped. The result is validated with `Configuration` before the slow steps.
  4. `npm ci`, `npx playwright install --with-deps chromium`, `npm run build`.
  5. `php bin/constellation --live --config <tmp>/config.json --output <tmp>/constellation.png`. The stats token is set only in this step's `env: GITHUB_TOKEN`.
  6. `scripts/action/publish.sh` publishes the PNG.
- Inputs are passed to scripts through `env`, never interpolated into `run:` scripts, so input text cannot inject shell code.
- No caching in this phase: it would add steps and keys to maintain, and a run every 6 hours does not need it.

## Tokens

- **Stats token** (`token` input): the automatic `GITHUB_TOKEN` by default (public statistics), or a classic PAT with only `read:user` in the secret `CONSTELLATION_TOKEN` (private counts for the token owner's own account).
- GitHub Actions tokens send no `X-OAuth-Scopes` header. The CLI then reports `Token scopes: (not reported)` and does not reject the token; the `repo`-scope rejection still applies to classic PATs, which report their scopes.
- The Actions token identifies as a bot in `viewer`, so `ownAccount` is false and the private state comes from restricted signals and arithmetic only (`included`, `excluded` or `unknown`), never from a wrong own-account match (`LiveStatisticsTest::testActionsBotViewerIsNeverTheOwnAccount`).
- Fine-grained PATs are accepted: they give public-only Pull Request and Code Review counts and report private activity as the restricted count (no private leak, verified in the Phase 2 spike). The classic `read:user` token is recommended.
- An empty stats token (for example a `secrets.CONSTELLATION_TOKEN` reference to a secret that was never created) fails the first step with a clear error; the value is never printed.
- **Publishing token**: always the automatic `GITHUB_TOKEN`, passed to the publish step as `GC_TOKEN`. The example checks out with `persist-credentials: false`; `publish.sh` sends the token as an `http.https://github.com/.extraheader` given through `GIT_CONFIG_COUNT`/`GIT_CONFIG_KEY_0`/`GIT_CONFIG_VALUE_0` on the `ls-remote`, `fetch` and `push` commands only, so it is never in a URL, in argv, in git config or in the output. No step uses `set -x`.

## Publishing (`scripts/action/publish.sh`)

The script works with Git plumbing in the checked-out repository (a temporary index, `hash-object`, `commit-tree`), so the working tree is never touched. The default branch is read from `origin` (`ls-remote --symref`).

- **Image branch** (default `constellation`, any branch other than the default): a single orphan commit holding only the PNG and a short `README.md`, force-pushed on each run. When the remote commit already has the same tree, nothing is pushed. If the branch exists but its tip is not a parentless commit whose `README.md` carries the generated marker, the script fails instead of overwriting a branch it did not create.
- **Default branch** (opt-in, `branch:` set to it): a normal commit on top of the remote tip, only when the PNG changed; never forced.
- Author and committer: `github-actions[bot] <41898099+github-actions[bot]@users.noreply.github.com>`.
- `path` must be a relative `.png` path without `..`, `.` or `.git` segments.
- Only the PNG is published: never `data.json`, configs or logs. A failure in any earlier step stops the job before publishing, so the previous image stays.

`tests/action/publish.test.sh` (run by `npm test`) checks both modes against a temporary bare repository: the orphan branch keeps exactly one commit and replaces it, unchanged images are no-ops, the default branch gets one commit per change and keeps its files, bad paths and a missing image fail, existing foreign branches (a normal branch and a foreign orphan) are refused and left untouched, a token passed in `GC_TOKEN` never appears in the output or git config, and the working tree stays clean.

## CI for this repository

`.github/workflows/ci.yml` runs on pushes and pull requests to `main` and `develop`: `composer validate --strict`, `composer test`, and `npm test` (build, Vitest, publishing test) on ubuntu-24.04. Visual snapshots are macOS-only and are not run in CI.

## Scheduled workflows and inactivity

GitHub disables scheduled workflows in public repositories after 60 days without repository activity. The user re-enables the workflow in the Actions tab (**Enable workflow**). Default-branch mode avoids it, since a changed image is a commit on the default branch. Whether bot force-pushes to the non-default `constellation` branch count as activity is not documented; the first real runs must tell.

## Verification checklist for the first real run

Do this in a profile repository after the first release tag exists (see [releasing.md](releasing.md)).

1. Copy `examples/profile-workflow.yml`, set `name`, `role`, `stack`, and run it with **Run workflow**.
2. Check the job log: runtime versions accepted (or Node set up), the summary shows the right login and period, `Token scopes: (not reported)`, and no repository names or token text anywhere.
3. Check that the `constellation` branch exists with exactly one commit by `github-actions[bot]`, containing only `constellation.png` and `README.md`, and that the default branch has no new commits.
4. Run it again: the branch still has one commit (replaced, or unchanged when the image is identical).
5. Add the `<img>` line to the README and check the image renders on the profile page.
6. Add `CONSTELLATION_TOKEN` (classic, `read:user` only) and the `token:` line; run again and check the summary shows `Token scopes: read:user`, `own account: yes`, `Private contributions: included`.
7. Try a token with `repo`: the job must fail with the `repo`-scope error and the image must stay unchanged.
8. Try `branch:` set to the default branch: one commit when the image changes, none when it does not.
9. Wait for a scheduled run and confirm it publishes.
10. Reference `secrets.CONSTELLATION_TOKEN` without creating it: the first step must fail with the empty-token error and print no token.
11. Confirm the push works with `persist-credentials: false` and the per-command header, and that the log shows no token or `AUTHORIZATION` text.
12. Find out whether bot pushes to the `constellation` branch count as repository activity for the 60-day schedule rule (watch the repository's scheduled-workflow status, or ask GitHub support / check the docs at the time).
