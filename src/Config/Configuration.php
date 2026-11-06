<?php

declare(strict_types=1);

namespace GitConstellation\Config;

use InvalidArgumentException;

final readonly class Configuration
{
    private function __construct(
        public string $username,
        public string $name,
        public string $role,
        public string $stack,
        public string $output,
        public string $cyan,
        public string $green,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?string $outputOverride = null): self
    {
        $unknown = array_diff(array_keys($data), ['username', 'name', 'role', 'stack', 'output', 'colors']);
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

        $output = $outputOverride ?? (array_key_exists('output', $data) ? $data['output'] : 'dist/constellation.png');
        if (!is_string($output) || trim($output) === '' || preg_match('/[\x00-\x1f\x7f]/', $output)
            || strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'png') {
            throw new InvalidArgumentException('output must be a non-empty PNG file path without control characters.');
        }

        return new self(
            $username,
            self::text($data['name'] ?? null, 'name', 100),
            self::text($data['role'] ?? null, 'role', 120),
            self::text($data['stack'] ?? null, 'stack', 120),
            $output,
            self::color(array_key_exists('cyan', $colors) ? $colors['cyan'] : '#30e8ef', 'cyan'),
            self::color(array_key_exists('green', $colors) ? $colors['green'] : '#52f5a0', 'green'),
        );
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
