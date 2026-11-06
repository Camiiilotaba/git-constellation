<?php

declare(strict_types=1);

namespace GitConstellation\Support;

use JsonException;
use RuntimeException;
use stdClass;

final class JsonFile
{
    /** @return array<string, mixed> */
    public static function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Cannot read JSON file: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read JSON file: {$path}");
        }

        try {
            if (!json_decode($contents, flags: JSON_THROW_ON_ERROR) instanceof stdClass) {
                throw new RuntimeException("JSON root must be an object: {$path}");
            }

            return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid JSON in {$path}: {$exception->getMessage()}", previous: $exception);
        }
    }

    /** @param array<string, mixed> $data */
    public static function write(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create directory: {$directory}");
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write JSON file: {$path}");
        }
    }
}
