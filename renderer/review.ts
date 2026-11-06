/** Local review artifacts only. The reference image never enters runtime assets. */
import { chromium } from '@playwright/test';
import { copyFile, mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

export async function createReview(): Promise<void> {
  const directory = resolve('build/review');
  await mkdir(directory, { recursive: true });
  await copyFile(resolve('dist/constellation.png'), resolve(directory, 'new.png'));
  const png = (await readFile(resolve(directory, 'new.png'))).toString('base64');
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({ deviceScaleFactor: 1 });
    await page.context().setOffline(true);
    for (const width of [960, 830]) {
      await page.setViewportSize({ width, height: Math.ceil(width * 840 / 1920) });
      await page.setContent(`<style>html,body{margin:0;background:#020a10}img{display:block;width:${width}px;height:auto}</style><img src="data:image/png;base64,${png}" alt="Git Constellation — simulated demo"/>`);
      await page.locator('img').evaluate(async node => { await (node as HTMLImageElement).decode(); });
      await page.locator('img').screenshot({ path: resolve(directory, `readme-${width}.png`), animations: 'disabled' });
    }
  } finally { await browser.close(); }
  const reference = (await readFile(resolve(directory, 'reference.png'))).toString('base64');
  const previous = await readFile(resolve(directory, 'before.png')).then(buffer => buffer.toString('base64'), () => undefined);
  const preview960 = (await readFile(resolve(directory, 'readme-960.png'))).toString('base64');
  const preview830 = (await readFile(resolve(directory, 'readme-830.png'))).toString('base64');
  const figure = (label: string, encoded: string, width = 1096): string => `<figure><figcaption>${label}</figcaption><img style="width:${width}px" src="data:image/png;base64,${encoded}" alt="${label}"/></figure>`;
  await writeFile(resolve(directory, 'comparison.html'), `<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Git Constellation · revisión de Fase 1</title><style>body{margin:24px;background:#10171d;color:#ecf4f5;font:16px/1.5 system-ui,sans-serif}main{max-width:1096px;margin:auto}h1{font-size:26px}figure{margin:32px 0}figcaption{margin:0 0 10px;font-weight:600}img{display:block;max-width:100%;height:auto}</style><main><h1>Git Constellation · revisión de Fase 1</h1><p>Referencia y render al mismo ancho visual, conservando su proporción. El nuevo render usa exclusivamente estadísticas simuladas. Las vistas de README son capturas del PNG reducido con Chromium, sin recomponer la interfaz.</p>${figure('Referencia original · solo para comparación', reference)}${previous ? figure('Render anterior · datos simulados', previous) : ''}${figure('Nuevo render · datos simulados · 1920 × 840', png)}${figure('PNG reducido a 830 px · ancho práctico de perfil · datos simulados', preview830, 830)}${figure('PNG reducido a 960 px · datos simulados', preview960, 960)}</main></html>`);
  process.stdout.write(`Review gallery: ${resolve(directory, 'comparison.html')}\n`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) await createReview();
