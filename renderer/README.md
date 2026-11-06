# Offline renderer

The PHP command prepares the validated data contract in `build/data.json`
(`schemaVersion` 2; see `../docs/phase-2.md`). This renderer consumes that
contract without making GitHub or other network requests. It renders both
modes: `demo` (simulated data) and `live` (statistics fetched by PHP). It
rejects data that makes inconsistent claims, such as a demo with a source or a
live file without one.

```sh
npm ci
npx playwright install chromium
npm run build
php bin/constellation --demo   # dist/constellation.png
php bin/constellation --live   # dist/live.png, needs GITHUB_TOKEN; see ../README.md
```

The normal output is a 1920 × 840 PNG (`dist/constellation.png` for demo,
`dist/live.png` for live) and a self-contained sibling HTML file. The HTML includes the fitted text sizes, local
font, stylesheet and SVG background. It can be inspected offline.

The renderer can also be called directly after PHP prepares the JSON:

```sh
node build/renderer/export.js --input build/data.json --output dist/constellation.png
```

Add `--no-html` to suppress the inspection HTML. PNG output is written through a
temporary file and renamed after successful rendering. Chromium closes on both
success and failure. Output does not contain source JSON or environment values.

## Contract v2 and mode-dependent copy

`validation.ts` requires `schemaVersion` 2, `mode` of `demo` or `live` and a
`privateContributions` state of `included`, `excluded` or `unknown`. Demo data
must report `excluded` with a null `source`; live data must carry a `source`
object (provider, login, restricted count, visible totals, repositories
scanned). `metrics.pullRequests` and `metrics.codeReviews` may be `null`.

The renderer never reads the token or any environment value; the PHP side also
filters credential-like variables from this process's environment.

| Element | Demo | Live |
| --- | --- | --- |
| Page title | `<name> · Git Constellation · Demo` | `<name> · Git Constellation` |
| Brand badge | `DEMO / SIMULATED DATA` | none |
| Contribution subtitle | `public activity only` | `private counts anonymized` (`included`), `public activity only` (`excluded`), `visible activity only` (`unknown`) |
| Contribution icon | calendar | lock when `included`, otherwise calendar |
| PR / review tooltip | `Simulated` | `Visible to the token; may exclude private activity` |
| Empty chart text | `No contributions in this example period` | `No contributions in this period` |
| Empty languages text | `No eligible public repositories in this example.` | `No eligible public repositories.` |
| Accessible description | states it is a demonstration with simulated data | states live statistics, the generation time and that PR and review counts may exclude private activity |

The language panel always says `By public repository count`.

## Responsibilities

- `validation.ts`: schema, calendar continuity and agreement of calendar metrics.
- `transform.ts`: UTC weekly aggregation, chart coordinates and interpolation.
- `ornaments.ts`: isolated peak accents and bounded decorative branches, separate from measured coordinates.
- `template.ts`: escaped HTML/SVG composition and bundled asset loading.
- `icons.ts`: original small outline SVG icons.
- `export.ts`: argument handling, offline Chromium, font loading and PNG export.
- `../assets/constellation.css`: readable static composition styles.
- `../assets/space.svg`: original provisional artwork without text or metrics.

## Measured constellation

Days represent UTC intervals from midnight to the following midnight. They are
grouped into weeks starting on Monday. Partial weeks at both period boundaries
are retained. Each star's x coordinate is the actual midpoint of its covered
daily intervals; edge weeks are not spaced as if they were full weeks.

Given weekly total `w` and maximum weekly total `peak` within this image:

```text
intensity = peak == 0 ? 0 : sqrt(w / peak)
y = bottom - intensity * (bottom - top)
radius = w == 0 ? 1.8 : 2.1 + 5.4 * (w / peak)^1.15
```

The square-root height uses a **dynamic domain** from zero to this image's weekly
maximum. Shapes in separate images cannot be used to compare absolute activity;
the total contribution number provides that context. Zero weeks remain on the
baseline. Radius, brightness and accent color use the raw `w / peak` ratio so
large weeks remain visually distinct. White cores highlight local maxima of at
least 55% of the largest week. At most five flares are selected in descending
activity order, at least 150 pixels apart; an equal neighboring plateau qualifies
as one event. This keeps uniform activity from producing a continuous row of flares.

The curve is a monotone cubic Hermite interpolation. It passes through measured
weekly points without overshooting neighboring values. Month labels use the
midpoint of the represented portion of each month. The fixed chart rectangle
leaves space below profile text even when the first week is the largest.

Up to three faint dashed branches connect auxiliary dots to existing stars.
They are explicitly `aria-hidden` and contain no weekly counts, dates or series
attributes. Their fixed offsets are decorative, bounded by the chart rectangle,
and never change measured positions or the continuous curve. Solid luminous
stars represent measured weeks; dashed, low-opacity branches have no statistical
meaning. Zero activity generates neither branches nor peak flares.

The decorative field, nebula, guide arcs, planet, rocks and developer are static
artwork. They have no statistical meaning and never determine data positions.

## Typography and bounds

Manrope is bundled under the SIL Open Font License; see
`../assets/fonts/OFL.txt`. No font service or CDN is contacted. Profile text fits
down to a bounded minimum font size and then truncates with an ellipsis; the
complete string remains in the HTML title attribute. Language names behave
similarly. Very large secondary metrics use compact English notation with the
full number in the title; ordinary counts retain thousands separators.

Node 24 is recommended. Supported versions are `^22.12.0 || >=24`, matching the
installed Vite runtime requirement. `@types/node` supplies TypeScript declarations
for filesystem, process and other Node APIs; it adds no renderer runtime code.

## Verification

```sh
npm test
npm run test:visual
```

`npm test` builds the command before running unit and CLI error tests. Visual
tests render entirely offline and cover the normal fixture, missing metrics,
zero activity, long text, huge counts, uniformly high weeks and an early peak.
The README tests embed the actual exported PNG at 960 and 830 pixels wide;
inspection copies are `build/visual-preview/readme-960.png` and
`build/visual-preview/readme-830.png`. Bounds checks supplement visual inspection;
they cannot determine human legibility automatically.

For a local comparison against the supplied reference, keep that image at
`build/review/reference.png` and optionally the previous render at
`build/review/before.png`. After generating the final PNG, run:

```sh
node build/renderer/review.js
open build/review/comparison.html
```

This local review helper copies the final PNG to `build/review/new.png`, captures
its downsampled 830/960 px previews with Playwright and writes a self-contained
comparison gallery. The original and new images appear at the same width while
preserving their individual aspect ratios. The reference stays outside runtime
assets and is never used in the generated constellation.

Screenshot baselines are in `tests/visual/constellation.spec.ts-snapshots/`.
The initial baselines were captured with pinned Playwright Chromium on macOS.
Playwright names baseline files by platform; a different OS needs separately
reviewed baselines. To intentionally regenerate them after a visual review:

```sh
npm run test:visual:update
```

Baseline changes require inspecting the generated images. Do not use the update
command to bypass a regression.
