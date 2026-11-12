<?php

declare(strict_types=1);

namespace GitConstellation\Config;

use InvalidArgumentException;
use JsonException;

/** Turns the GitHub Action inputs into a configuration array; non-empty inputs override the optional config file. */
final class ActionInputs
{
    private const array TEXT_INPUTS = ['username' => 'username', 'name' => 'name', 'role' => 'role', 'stack' => 'stack', 'period' => 'period'];
    private const array JSON_INPUTS = ['languages' => 'languages', 'exclude-languages' => 'excludeLanguages', 'colors' => 'colors'];

    /**
     * @param array<string, string> $inputs action input name => raw value, '' meaning not set
     * @param array<string, mixed> $base the caller's config file, if any
     * @param string $defaultUsername the repository owner, used when neither the inputs nor the file name a user
     * @return array<string, mixed>
     */
    public static function toConfig(array $inputs, array $base = [], string $defaultUsername = ''): array
    {
        $config = $base;
        foreach (self::TEXT_INPUTS as $input => $key) {
            $value = trim($inputs[$input] ?? '');
            if ($value !== '') {
                $config[$key] = $value;
            }
        }
        foreach (self::JSON_INPUTS as $input => $key) {
            $value = trim($inputs[$input] ?? '');
            if ($value !== '') {
                $config[$key] = self::json($value, $input);
            }
        }
        if (!isset($config['username']) && $defaultUsername !== '') {
            $config['username'] = $defaultUsername;
        }
        foreach (['name', 'role', 'stack'] as $key) {
            if (!isset($config[$key])) {
                throw new InvalidArgumentException("The {$key} input is required (or set \"{$key}\" in the config file).");
            }
        }
        // The action always passes --output, so a path from the caller's file would only mislead.
        unset($config['output']);

        return $config;
    }

    private static function json(string $value, string $input): mixed
    {
        try {
            return json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException("The {$input} input must be valid JSON.");
        }
    }
}
