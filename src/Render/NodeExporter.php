<?php

declare(strict_types=1);

namespace GitConstellation\Render;

use RuntimeException;

final class NodeExporter
{
    /**
     * @param array<string, string|false> $environment
     * @return array<string, string|false>
     */
    public static function childEnvironment(array $environment, #[\SensitiveParameter] ?string $secret = null): array
    {
        // Names alone miss a token exported under an innocuous name such as GH_PAT, so the value is matched too.
        return array_filter(
            $environment,
            fn (string|false $value, string $name): bool => preg_match('/TOKEN|SECRET|PASSWORD/i', $name) !== 1
                && ($secret === null || $value !== $secret),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public static function animatedPath(string $output): string
    {
        return (string) preg_replace('/\.png$/i', '.svg', $output);
    }

    public function export(string $projectRoot, string $input, string $output, #[\SensitiveParameter] ?string $secret = null): void
    {
        $script = $projectRoot . '/build/renderer/export.js';
        if (!is_file($script)) {
            throw new RuntimeException('Renderer is not built. Run npm install and npm run build first.');
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException('PHP proc_open is required to run the Node renderer.');
        }
        $directory = dirname($output);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create output directory: {$directory}");
        }

        // An argument array bypasses the shell, including for paths containing spaces or metacharacters.
        // Inherit stdout/stderr directly to avoid pipe deadlocks and expose useful Playwright diagnostics.
        $process = @proc_open(
            ['node', $script, '--input', $input, '--output', $output],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
            $pipes,
            $projectRoot,
            // The renderer needs no credentials, so the child gets a filtered environment in case a token is set in the shell.
            self::childEnvironment(getenv(), $secret),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start Node. Install Node.js and ensure node is available on PATH.');
        }
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException("Renderer failed with exit code {$exitCode}. Check Node.js, the renderer build, and Playwright Chromium installation.");
        }
        clearstatcache(true, $output);
        if (!is_file($output) || filesize($output) === 0) {
            throw new RuntimeException('Renderer exited successfully but did not produce a PNG image.');
        }
        $handle = @fopen($output, 'rb');
        $signature = $handle === false ? false : fread($handle, 8);
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($signature !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('Renderer output does not have a valid PNG signature.');
        }
        $animated = self::animatedPath($output);
        clearstatcache(true, $animated);
        if (!is_file($animated) || filesize($animated) === 0) {
            throw new RuntimeException('Renderer exited successfully but did not produce the animated SVG.');
        }
    }
}
