# Phase 1: structure and first visual version

> Phase 2 changed the data contract to `schemaVersion` 2 (live mode, `privateContributions`, `source`). This document describes the Phase 1 contract; see [phase-2.md](phase-2.md) for the current v2 contract.

## Goal

Create a first runnable version that generates, from fixed fictional data, a panoramic PNG inspired by the visual reference. This phase exists to review composition, proportions, hierarchy and legibility before starting the GitHub integration.

The demo output must be clearly identified as an example. It must not suggest that its statistics belong to a real user.

## Architecture

- The PHP CLI loads the configuration and fixture, validates and normalizes the data, and writes `build/data.json`.
- The PHP command starts the Node exporter with separate arguments, requests the output PNG and checks the process for errors.
- TypeScript transforms the normalized data into SVG/HTML elements and Playwright renders the result to PNG. `npm run build` compiles `renderer/*.ts` to `build/renderer/*.js` as ESM modules.
- Fixtures, render data and visual design stay separate so the visualization can be reviewed without calls to external services.

## Data contract between PHP and the renderer

The JSON prepared by PHP has this shape:

```json
{
  "schemaVersion": 1,
  "mode": "demo",
  "profile": {
    "username": "demo-user",
    "name": "Demo Developer",
    "role": "Backend Developer",
    "stack": "PHP / Laravel"
  },
  "theme": {
    "cyan": "#30e8ef",
    "green": "#52f5a0"
  },
  "period": {
    "start": "2025-11-01",
    "end": "2026-10-31"
  },
  "days": [
    { "date": "2025-11-01", "count": 2 }
  ],
  "metrics": {
    "totalContributions": 2,
    "activeDays": 1,
    "longestStreak": 1,
    "pullRequests": null,
    "codeReviews": null
  },
  "languages": [
    { "name": "PHP", "repositories": 2, "color": "#4f5d95" }
  ],
  "privateContributionsIncluded": false
}
```

The fixture must include every day of the period, inclusively, with unique dates; the backend sorts the input and rejects gaps and duplicates. Daily counts are non-negative integers. The pull request and review metrics accept `null` when unavailable. Theme colors are hexadecimal values. The values in this example are illustrative.

## Agreed CLI

The agreed CLI interface for generating the demo is:

```sh
php bin/constellation --demo \
  [--config=config/demo.json] \
  [--fixture=fixtures/demo.json] \
  [--output=dist/constellation.png]
```

The process first generates `build/data.json` and then invokes:

```sh
node build/renderer/export.js --input build/data.json --output <png>
```

By default the exporter also saves an HTML file next to the PNG. The `--no-html` option is accepted when invoking Node directly; the PHP CLI does not expose it.

The command accepts options as `--option=value` or `--option value`, resolves relative paths from the project root and prints help with `--help`. It validates errors and reports Node and Playwright failures.

## Constellation transform

The chart is computed from the real days of the fixture, with no randomness in point positions. Days are grouped into UTC weeks starting on Monday; the first and last partial groups are included. The horizontal coordinate uses the temporal midpoint of the group. Height and intensity represent the weekly total; the connections follow a smooth curve. Background ornaments may be decorative but do not alter the data.

The current implementation uses `sqrt(weekly total / maximum weekly total of the period)`, with zero intensity when there is no activity in the whole period. The maximum is thus normalized to one and the square root spreads activity across the available vertical space. The scale is relative to the period and does not allow comparing absolute counts between images.

Brightness and size vary with the relative weekly total. Up to five separated local maxima receive flares; a uniform plateau counts as a single maximum. The faint dashed branches are explicit decoration, without counts or dates, and never alter the measured points or their continuous curve. The original fixture values are preserved.

The demo period is fixed so that the same fixture produces the same temporal shape. Live mode and any rolling period are outside this phase.

## Visual direction

The composition keeps the identity of the reference: profile and total on the left, constellation and months in the center, languages on the right and secondary metrics at the bottom. The period sits next to the contribution total and there is no footer. The dark background, the cyan/teal/green tones, the planetary horizon, a discreet silhouette and translucent panels with thin borders are kept.

The reference screenshot is not used as a background. The initial background can be a provisional SVG/CSS illustration and must keep the artistic direction; it is not presented as final art or an exact replica. If the panoramic composition makes the text too small, a variant with the hero and the secondary statistics separated should be tried, keeping the same identity.

## Acceptance criteria

- The documented installation matches the real requirements of PHP, Composer, Node, npm and Playwright.
- Demo mode works without a token, network calls or credentials.
- The fixture covers a fixed period and each date appears exactly once, without gaps; PHP sorts the dates before normalizing them.
- The CLI prepares the agreed JSON and calls the exporter with safe arguments and checkable errors.
- Playwright produces `dist/constellation.png` and, by default, `dist/constellation.html`. The generated PNG is 1920 × 840 pixels.
- Demo data is identified in the image as a sample.
- The central curve reflects the fixture's weekly aggregations, including the partial groups at both ends, and uses no random data to place it.
- The composition is inspected against the reference at panoramic size and the important legibility and hierarchy problems are fixed.
- The documentation describes only what exists and clearly distinguishes what is implemented from what is pending.

## Out of scope

This phase does not implement a GraphQL/REST client, access to live statistics, a language ranking computed from real repositories, workflows, automated publishing, a server, a database or credentials. The future policy requires limiting private activity to authorized anonymized totals, not reading private code and not disclosing private metadata. Pull request and review breakdowns will not be assumed complete if the API or the permissions do not provide them.

Visual review and approval of this phase precede the start of later phases.

## Verification commands and results

These are the Phase 1 results; later phases have added tests.

- `composer validate --strict`: passed.
- `composer test`: passed, 74 tests and 145 assertions. PHPUnit keeps its cache in `build/phpunit-cache`.
- `npm test`: the `pretest` hook runs `npm run build` before Vitest. Result: 50 tests passed.
- `npm run test:visual`: six tests; seven screenshots with the special cases and the README widths of 830 and 960 pixels.
- `npm run test:visual:update`: explicitly updates the reference screenshots.

The seven reference screenshots in `tests/visual/constellation.spec.ts-snapshots/` correspond to macOS and Chromium. They include the PNG at README widths of 830 and 960 pixels and the calendar without activity. The reduced versions need human review of legibility; the automated tests check bounds and visual changes. On another operating system, generate specific references with `npm run test:visual:update` and review them explicitly. The normal visual tests compare against the existing references and must not regenerate them.

The local gallery `build/review/comparison.html` shows the original reference, the previous render when it exists, the new PNG and the README views. It is generated with `node build/renderer/review.js` after the demo and needs the reference at `build/review/reference.png`. The original images are shown at the same width preserving their aspect ratio and are not used as a runtime background.

The generated PNG is at `dist/constellation.png` and is 1920 × 840 pixels. Phase 1 is ready for visual review; it is not yet approved to start later phases.
