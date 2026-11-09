<?php

declare(strict_types=1);

// Writes the configuration for one action run from GC_* environment variables set by action.yml.
// Usage: php scripts/action/config.php <output.json>

use GitConstellation\Config\ActionInputs;
use GitConstellation\Config\Configuration;
use GitConstellation\Support\JsonFile;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $output = $argv[1] ?? throw new InvalidArgumentException('Usage: php scripts/action/config.php <output.json>');
    $inputs = [];
    foreach (['username', 'name', 'role', 'stack', 'period', 'languages', 'exclude-languages', 'colors'] as $input) {
        $value = getenv('GC_' . strtoupper(str_replace('-', '_', $input)));
        $inputs[$input] = is_string($value) ? $value : '';
    }

    $configFile = (string) getenv('GC_CONFIG');
    $base = [];
    if (trim($configFile) !== '') {
        $workspace = (string) getenv('GITHUB_WORKSPACE');
        $path = str_starts_with($configFile, '/') ? $configFile : rtrim($workspace, '/') . '/' . $configFile;
        if (!is_file($path)) {
            throw new RuntimeException("Config file not found: {$configFile}. Check out the repository with actions/checkout first.");
        }
        $base = JsonFile::read($path);
    }

    $config = ActionInputs::toConfig($inputs, $base, (string) getenv('GITHUB_REPOSITORY_OWNER'));
    // Validated here so a bad input fails before the slow install and render steps.
    Configuration::fromArray($config, 'constellation.png');
    JsonFile::write($output, $config);
} catch (InvalidArgumentException|RuntimeException $exception) {
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
