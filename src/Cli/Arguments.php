<?php

declare(strict_types=1);

namespace GitConstellation\Cli;

use InvalidArgumentException;

final readonly class Arguments
{
    private function __construct(
        public bool $help,
        public string $config,
        public string $fixture,
        public ?string $output,
    ) {}

    /** @param list<string> $arguments Arguments excluding the command name. */
    public static function parse(array $arguments): self
    {
        if ($arguments === ['--help'] || $arguments === ['-h']) {
            return new self(true, 'config/demo.json', 'fixtures/demo.json', null);
        }
        $options = [];
        for ($index = 0; $index < count($arguments); ++$index) {
            $parts = explode('=', $arguments[$index], 2);
            $option = $parts[0];
            if (!in_array($option, ['--demo', '--config', '--fixture', '--output'], true)) {
                throw new InvalidArgumentException("Unknown argument: {$option}. Use --help for usage.");
            }
            if (array_key_exists($option, $options)) {
                throw new InvalidArgumentException("Duplicate argument: {$option}");
            }
            if ($option === '--demo') {
                if (count($parts) !== 1) {
                    throw new InvalidArgumentException('--demo does not take a value.');
                }
                $options[$option] = true;
                continue;
            }
            $value = $parts[1] ?? ($arguments[++$index] ?? null);
            if (!is_string($value) || trim($value) === '' || str_starts_with($value, '--')
                || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException("{$option} requires a non-empty path without control characters.");
            }
            $options[$option] = $value;
        }
        if (!isset($options['--demo'])) {
            throw new InvalidArgumentException('Phase 1 supports demo mode only. Pass --demo. No GitHub API calls are made.');
        }

        return new self(false, $options['--config'] ?? 'config/demo.json', $options['--fixture'] ?? 'fixtures/demo.json', $options['--output'] ?? null);
    }
}
