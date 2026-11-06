import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/visual',
  outputDir: './build/test-results',
  fullyParallel: false,
  workers: 1,
  reporter: 'list',
  use: {
    viewport: { width: 1920, height: 840 },
    deviceScaleFactor: 1,
    locale: 'en-US',
    timezoneId: 'UTC',
    colorScheme: 'dark',
    browserName: 'chromium',
  },
  expect: { toHaveScreenshot: { maxDiffPixelRatio: 0.002, animations: 'disabled' } },
});
