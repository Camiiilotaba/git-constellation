# Phase 4: Animated SVG

## Goal

The profile README can show the constellation as a slowly moving image (stars twinkle, light runs along the data line, shooting stars cross the sky) without scripts and without a second render path. The static PNG stays available for anyone who prefers it.

## What it is

Every run writes `<output>.svg` next to `<output>.png` (`dist/constellation.svg` for the demo, `dist/live.svg` for live mode; in the action, `constellation.svg` next to `constellation.png` in the image branch). The Node exporter builds it from the same page scene as the PNG (`renderer/animation.ts`) and prints `Rendered <path>.svg`; the PHP CLI reports it as `Generated demo animation:` or `Generated live animation:`. `NodeExporter` fails the run when the SVG is missing or empty, so the action never publishes a PNG without its SVG.

The README embeds it with an ordinary `<img>`; no JavaScript runs inside an image.

## How it is built

- **Base:** the rendered image as an embedded JPEG (quality 92). About 290 KB instead of 1.5 MB for a PNG; PSNR against the PNG is 39.5 dB, not visible at README size. The whole file is about 343 KB for the demo.
- **Layers:** CSS-animated vector elements on top of the base: twinkling stars, a light running along the data line with ripples on local peaks and a glow on flare stars, three shooting stars (13, 19 and 23 s loops), the orbit comet and logo glow, the laptop screen glow, and the planet rim glow and glint.
- **Invisible at rest:** every animated element has opacity 0 when no animation runs, and animated dashes are parked off the path. A viewer that does not animate the SVG therefore shows the plain image. The SVG contains a `prefers-reduced-motion: reduce` rule that disables the animations. An emulated `prefers-reduced-motion` was not passed into an SVG shown through `<img>` in Chromium; whether a real operating-system setting reaches it was not verified, so treat the plain-image fallback (not the media query) as the guarantee.

## Performance rules

Every animation step makes the browser redraw the whole image, whatever the size of the changed area, so cost is proportional to redraws per second. The rules follow from measurements:

- **One 30 fps clock.** Every animation is quantized with `steps()` per keyframe segment, all delays are multiples of one frame, plus a 1/240 s offset so step boundaries do not sit on display sampling instants. Without it, animations tick at the display rate (240 Hz on the owner's monitor). Idle animations cost nothing.
- **No filters.** Blur filters on animated elements were the largest cost. Glows are stacked translucent strokes and radial gradients.
- **Twelve star paths in four sky bands.** WebKit pays per simultaneously changing element (70 separate twinkles dropped the page to about 15 fps, 10 kept 60 fps), so each twinkle group is one `<path>` of sparkle polygons. Firefox replays a path in every raster tile its bounds touch, so each path stays inside one vertical band of the sky: 4 columns by 3 rhythms.
- **No group opacity, no per-star gradients.** Single-fill paths fold opacity into the paint.

## Measured CPU

Process CPU while the image is shown 1012 CSS px wide at 2x, headed, median of two rounds, as a percentage of one core. The `static.png` row is the harness overhead. The numbers are noisy between runs (other load on the machine), so compare within a run only.

| Variant | Chromium | Firefox | WebKit |
| --- | --- | --- | --- |
| static.png | 8 | 6 | 6 |
| first prototype (blur filters, 120 separate twinkles, unquantized) | 42 | 113 | 81 |
| final, 30 fps clock | 27 | 57 | 33 |
| final, 20 fps clock | 20 | 37 | 21 |

The shipped version uses the 30 fps clock. Source: `.superpowers/sdd/2026-11-12-animation-spike/NOTES.md` (local, not part of the repository).

## Layout change

The second `</>` mark beside the contribution count was removed, and the contribution line is aligned with the name. This changes the static PNG too.

## Publishing

`action.yml` passes `GC_ANIMATED` to `scripts/action/publish.sh`. The SVG is always published, to the `path` input with `.png` replaced by `.svg` (default `constellation.svg`); there is no new input. On the image branch the single commit holds the README marker, the PNG and the SVG, and nothing is pushed when both are unchanged. In default-branch mode a commit is made when either file changed. Without `GC_ANIMATED` the script behaves as in Phase 3.

## Not verified yet

- **Rendering on GitHub itself.** raw.githubusercontent.com serves images with `content-security-policy: default-src 'none'; style-src 'unsafe-inline'; sandbox`. With those headers reproduced locally, the embedded data image, CSS animation and SMIL worked in Chromium, Firefox and WebKit. Nothing has been published, so the real behaviour on a profile page is unconfirmed. The hero image of the README keeps the PNG URL until a real run confirms it.
- `prefers-reduced-motion` from a real OS setting (see above).
- CPU cost on other machines and on battery-powered devices.
