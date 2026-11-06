<?php

declare(strict_types=1);

namespace GitConstellation\Config;

use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Dotenv\Exception\FormatException;

final readonly class Environment
{
    private function __construct(
        #[\SensitiveParameter] public string $githubToken,
    ) {}

    /** @param array<string, string|false> $processEnvironment */
    public static function load(string $projectRoot, array $processEnvironment): self
    {
        // The process environment wins because CI and shells set it explicitly.
        $token = self::nonEmpty($processEnvironment['GITHUB_TOKEN'] ?? null)
            ?? self::fromDotEnv($projectRoot . '/.env');

        if ($token === null) {
            throw new RuntimeException(
                "GITHUB_TOKEN is not set. Live mode needs a GitHub token with the read:user scope in the environment or in {$projectRoot}/.env."
            );
        }

        if (preg_match('/[\s\p{C}]/u', $token) === 1) {
            throw new RuntimeException('GITHUB_TOKEN contains whitespace or control characters.');
        }

        return new self($token);
    }

    private static function fromDotEnv(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        try {
            // parse() instead of load(): the token must stay out of the process environment
            // so child processes such as the Node renderer cannot inherit it.
            $values = (new Dotenv())->parse($contents, $path);
        } catch (FormatException) {
            throw new RuntimeException("Could not parse {$path}.");
        }

        return self::nonEmpty($values['GITHUB_TOKEN'] ?? null);
    }

    private static function nonEmpty(string|false|null $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
