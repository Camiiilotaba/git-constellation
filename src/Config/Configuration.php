<?php

declare(strict_types=1);

namespace GitConstellation\Config;

use InvalidArgumentException;

final readonly class Configuration
{
    public const array PERIODS = ['all-time', 'last-year'];

    private function __construct(
        public string $username,
        public string $name,
        public string $role,
        public string $stack,
        public string $output,
        public string $cyan,
        public string $green,
        /** @var list<string>|null Replaces the default exclusion list when set; an empty list disables exclusions. */
        public ?array $excludeLanguages,
        /** @var list<array{name: string, color: string|null}>|null The developer's own language list, 1 to 5 entries. */
        public ?array $languages,
        /** Live mode only: 'all-time' (default) or 'last-year'. Demo always renders its fixture year. */
        public string $period,
    ) {}


    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?string $outputOverride = null, string $defaultOutput = 'dist/constellation.png'): self
    {
        $unknown = array_diff(array_keys($data), ['username', 'name', 'role', 'stack', 'output', 'colors', 'excludeLanguages', 'languages', 'period']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown configuration field: ' . implode(', ', $unknown));
        }

        $username = self::text($data['username'] ?? null, 'username', 39);
        if (!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38}$/D', $username)) {
            throw new InvalidArgumentException('username must be a valid GitHub username.');
        }

        $colors = array_key_exists('colors', $data) ? $data['colors'] : [];
        if (!is_array($colors) || ($colors !== [] && array_is_list($colors))) {
            throw new InvalidArgumentException('colors must be an object with optional cyan and green colors.');
        }
        if (array_diff(array_keys($colors), ['cyan', 'green']) !== []) {
            throw new InvalidArgumentException('colors only supports cyan and green.');
        }

        $output = $outputOverride ?? (array_key_exists('output', $data) ? $data['output'] : $defaultOutput);
        if (!is_string($output) || trim($output) === '' || preg_match('/[\x00-\x1f\x7f]/', $output)
            || strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'png') {
            throw new InvalidArgumentException('output must be a non-empty PNG file path without control characters.');
        }

        $period = array_key_exists('period', $data) ? $data['period'] : 'all-time';
        if (!in_array($period, self::PERIODS, true)) {
            throw new InvalidArgumentException('period must be "all-time" or "last-year".');
        }

        return new self(
            $username,
            self::text($data['name'] ?? null, 'name', 100),
            self::text($data['role'] ?? null, 'role', 120),
            self::text($data['stack'] ?? null, 'stack', 120),
            $output,
            self::color(array_key_exists('cyan', $colors) ? $colors['cyan'] : '#30e8ef', 'cyan'),
            self::color(array_key_exists('green', $colors) ? $colors['green'] : '#52f5a0', 'green'),
            array_key_exists('excludeLanguages', $data) ? self::excludeLanguages($data['excludeLanguages']) : null,
            array_key_exists('languages', $data) ? self::languages($data['languages']) : null,
            $period,
        );
    }

    /** @return list<string> */
    private static function excludeLanguages(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('excludeLanguages must be a list of language names.');
        }

        return array_map(static fn (mixed $name): string => self::text($name, 'excludeLanguages entries', 80), $value);
    }

    /** @return list<array{name: string, color: string|null}> */
    private static function languages(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 5) {
            throw new InvalidArgumentException('languages must be a list of 1 to 5 entries.');
        }
        $languages = [];
        $seen = [];
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $entry = ['name' => $entry];
            }
            if (!is_array($entry) || array_is_list($entry) || array_diff(array_keys($entry), ['name', 'color']) !== []) {
                throw new InvalidArgumentException('languages entries must be a name or an object with name and optional color.');
            }
            $name = self::text($entry['name'] ?? null, 'languages name', 80);
            if (isset($seen[mb_strtolower($name)])) {
                throw new InvalidArgumentException("languages must not contain duplicate names: {$name}");
            }
            $seen[mb_strtolower($name)] = true;
            $color = array_key_exists('color', $entry) ? $entry['color'] : null;
            if ($color !== null && (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $color))) {
                throw new InvalidArgumentException('languages color must be a six-digit hex color, such as #3178c6.');
            }
            $languages[] = ['name' => $name, 'color' => $color === null ? null : strtolower($color)];
        }

        return $languages;
    }

    private static function text(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $maxLength
            || preg_match('/[\p{C}]/u', $value) !== 0) {
            throw new InvalidArgumentException("{$field} must be non-empty text of at most {$maxLength} bytes without control characters.");
        }

        return trim($value);
    }

    private static function color(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $value)) {
            throw new InvalidArgumentException("colors.{$field} must be a six-digit hex color, such as #30e8ef.");
        }

        return strtolower($value);
    }
}
