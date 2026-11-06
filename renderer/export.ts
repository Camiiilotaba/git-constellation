import { chromium, type Page } from '@playwright/test';
import { mkdir, readFile, rename, rm, stat, writeFile } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
import { dirname, extname, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { loadAssets, renderHtml } from './template.js';
import { validateData } from './validation.js';

export interface ExportOptions { input: string; output: string; html: boolean }

export function parseArguments(args: string[]): ExportOptions {
  let input: string | undefined;
  let output: string | undefined;
  let html = true;
  const seen = new Set<string>();
  for (let index = 0; index < args.length; index++) {
    const arg = args[index]!;
    if (seen.has(arg)) throw new Error(`Duplicate option: ${arg}`);
    seen.add(arg);
    if (arg === '--no-html') { html = false; continue; }
    if (arg !== '--input' && arg !== '--output') throw new Error(`Unknown option: ${arg}`);
    const value = args[++index];
    if (!value || value.startsWith('--')) throw new Error(`${arg} requires a path.`);
    if (arg === '--input') input = value;
    else output = value;
  }
  if (!input || !output) throw new Error('Usage: node build/renderer/export.js --input <data.json> --output <image.png> [--no-html]');
  if (extname(output).toLowerCase() !== '.png') throw new Error('--output must have a .png extension.');
  return { input: resolve(input), output: resolve(output), html };
}

/** Used by export and screenshot tests so fit/font behavior is identical. */
export async function preparePage(page: Page, html: string): Promise<void> {
  await page.context().setOffline(true);
  await page.route('**/*', route => route.abort('blockedbyclient'));
  await page.setContent(html, { waitUntil: 'load', timeout: 30_000 });
  await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.all([...document.images].map(image => image.decode()));
    for (const element of document.querySelectorAll<HTMLElement>('[data-fit]')) {
      const min = Number(element.dataset.minSize ?? 20);
      let size = parseFloat(getComputedStyle(element).fontSize);
      // Contributions use mixed sizes. Scale the entire row at extreme counts.
      if (element.classList.contains('contribution-total')) {
        const max = 1075;
        if (element.scrollWidth > max) {
          element.style.transformOrigin = 'left center';
          element.style.transform = `scale(${max / element.scrollWidth})`;
        }
        continue;
      }
      while (element.scrollWidth > element.clientWidth && size > min) {
        size -= 1;
        element.style.fontSize = `${size}px`;
      }
    }
    if (!document.fonts.check('600 20px Manrope')) throw new Error('The bundled Manrope font did not load.');
  });
}

export async function exportImage(options: ExportOptions): Promise<void> {
  if ((await stat(options.input)).size > 2_000_000) throw new Error('Input JSON exceeds the 2 MB limit.');
  const contents = await readFile(options.input, 'utf8');
  let parsed: unknown;
  try { parsed = JSON.parse(contents) as unknown; }
  catch { throw new Error('Input JSON is invalid.'); }
  const data = validateData(parsed);
  const html = renderHtml(data, await loadAssets());
  await mkdir(dirname(options.output), { recursive: true });
  const temporary = `${options.output}.${randomUUID()}.tmp`;
  const htmlPath = options.output.replace(/\.png$/i, '.html');
  const temporaryHtml = `${htmlPath}.${randomUUID()}.tmp`;
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1920, height: 840 }, deviceScaleFactor: 1, locale: 'en-US', timezoneId: 'UTC' });
    await preparePage(page, html);
    await page.locator('main').screenshot({ path: temporary, type: 'png', animations: 'disabled', timeout: 30_000 });
    if (options.html) {
      // Persist the fitted DOM as well, so the sibling HTML matches the PNG.
      await writeFile(temporaryHtml, await page.content(), 'utf8');
      await rename(temporaryHtml, htmlPath);
    }
    await rename(temporary, options.output);
  } finally {
    await browser.close();
    await Promise.all([rm(temporary, { force: true }), rm(temporaryHtml, { force: true })]);
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  if (process.argv.slice(2).length === 1 && process.argv[2] === '--help') {
    process.stdout.write('Usage: node build/renderer/export.js --input <data.json> --output <image.png> [--no-html]\n');
  } else {
    try {
      const options = parseArguments(process.argv.slice(2));
      await exportImage(options);
      process.stdout.write(`Rendered ${options.output}\n`);
    } catch (error) {
      // Never echo untrusted input JSON, environment values or raw browser logs.
      const message = error instanceof Error ? error.message.split('\n')[0] : 'Image generation failed.';
      process.stderr.write(`Render error: ${message}\n`);
      process.exitCode = 1;
    }
  }
}
