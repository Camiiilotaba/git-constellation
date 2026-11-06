import { spawnSync } from 'node:child_process';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { exportImage, parseArguments } from '../../renderer/export.js';

describe('export command boundary', () => {
  it('accepts explicit argument paths and optional HTML suppression', () => {
    expect(parseArguments(['--input', 'build/data.json', '--output', 'build/test image.png', '--no-html'])).toMatchObject({ html: false });
  });

  it.each([
    [], ['--input'], ['--output', 'x.png'],
    ['--input', 'x.json', '--output', 'x.svg'],
    ['--input', 'x.json', '--input', 'y.json', '--output', 'x.png'],
    ['--input', 'x.json', '--output', 'x.png', '--bad'],
  ])('rejects malformed arguments %j', args => { expect(() => parseArguments(args)).toThrow(); });

  it('does not echo malformed JSON in the error or CLI stderr', async () => {
    const directory = await mkdtemp(join(tmpdir(), 'git-constellation-invalid-'));
    try {
      const input = join(directory, 'input.json');
      const output = join(directory, 'output.png');
      await writeFile(input, '{"SENSITIVE_SENTINEL": "broken');
      await expect(exportImage({ input, output, html: false })).rejects.toThrow('Input JSON is invalid.');
      const result = spawnSync(process.execPath, ['build/renderer/export.js', '--input', input, '--output', output], { encoding: 'utf8' });
      expect(result.status).toBe(1);
      expect(result.stderr).toBe('Render error: Input JSON is invalid.\n');
      expect(result.stderr).not.toContain('SENSITIVE_SENTINEL');
    } finally { await rm(directory, { recursive: true, force: true }); }
  });
});
