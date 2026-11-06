<?php

declare(strict_types=1);

namespace GitConstellation\Cli;

use InvalidArgumentException;

final readonly class Arguments
{
    private function __construct(
        public bool $help,
        public bool $live,
        public string $config,
        public string $fixture,
        public ?string $output,
    ) {}

    /** @param list<string> $arguments Arguments excluding the command name. */
    public static function parse(array $arguments): self
    {
        if ($arguments === ['--help'] || $arguments === ['-h']) {
            return new self(true, false, 'config/demo.json', 'fixtures/demo.json', null);
        }
        $options = [];
        for ($index = 0; $index < count($arguments); ++$index) {
            $parts = explode('=', $arguments[$index], 2);
            $option = $parts[0];
            if (!in_array($option, ['--demo', '--live', '--config', '--fixture', '--output'], true)) {
                throw new InvalidArgumentException("Unknown argument: {$option}. Use --help for usage.");
            }
            if (array_key_exists($option, $options)) {
                throw new InvalidArgumentException("Duplicate argument: {$option}");
            }
            if ($option === '--demo' || $option === '--live') {
                if (count($parts) !== 1) {
                    throw new InvalidArgumentException("{$option} does not take a value.");
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
        $live = isset($options['--live']);
        if ($live && isset($options['--demo'])) {
            throw new InvalidArgumentException('Choose either --demo or --live, not both.');
        }
        if (!$live && !isset($options['--demo'])) {
            throw new InvalidArgumentException('Pass --demo (simulated data) or --live (GitHub data). Use --help for usage.');
        }
        if ($live && isset($options['--fixture'])) {
            throw new InvalidArgumentException('--fixture only applies to --demo.');
        }

        return new self(
            false,
            $live,
            $options['--config'] ?? ($live ? 'config/local.json' : 'config/demo.json'),
            $options['--fixture'] ?? 'fixtures/demo.json',
            $options['--output'] ?? null,
        );
    }
}
