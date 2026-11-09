<?php

declare(strict_types=1);

namespace GitConstellation\Statistics;

use InvalidArgumentException;

/** Counts each detected language once per eligible public repository, including secondary languages. */
final class LanguageRanking
{
    private const string FALLBACK_COLOR = '#8b949e';

    // GitHub's GraphQL Language object does not expose Linguist's language type, so non-programming languages are listed by name.
    public const array DEFAULT_EXCLUDED = ['CSS', 'SCSS', 'Sass', 'Less', 'Stylus', 'HTML', 'Markdown', 'MDX', 'Dockerfile', 'Makefile', 'Mako', 'Jinja', 'Twig', 'Blade', 'Handlebars', 'Mustache', 'Pug', 'XML', 'JSON', 'YAML', 'TOML', 'Text', 'Procfile'];

    /**
     * @param array<mixed> $repositories Simulated repository metadata, never repository source code.
     * @param list<string> $excluded Language names left out of the ranking, compared case-insensitively.
     * @return list<array{name: string, repositories: int, color: string}>
     */
    public static function calculate(array $repositories, array $excluded = self::DEFAULT_EXCLUDED): array
    {
        $excludedNames = array_flip(array_map(mb_strtolower(...), $excluded));
        if (!array_is_list($repositories)) {
            throw new InvalidArgumentException('repositories must be a list.');
        }
        $counts = [];
        foreach ($repositories as $repository) {
            if (!is_array($repository) || !is_bool($repository['isFork'] ?? null)
                || !is_bool($repository['isArchived'] ?? null)
                || !in_array($repository['visibility'] ?? null, ['PUBLIC', 'PRIVATE', 'INTERNAL'], true)) {
                throw new InvalidArgumentException('Each repository requires visibility, isFork, and isArchived metadata.');
            }
            if ($repository['visibility'] !== 'PUBLIC' || $repository['isFork'] || $repository['isArchived']) {
                continue;
            }
            $languages = $repository['languages'] ?? null;
            if (!is_array($languages) || !array_is_list($languages)) {
                throw new InvalidArgumentException('Repository languages must be a list.');
            }
            $seen = [];
            foreach ($languages as $language) {
                if (!is_array($language) || !is_string($language['name'] ?? null)
                    || trim($language['name']) === '' || strlen($language['name']) > 80
                    || preg_match('/[\p{C}]/u', $language['name']) !== 0
                    || !is_string($language['color'] ?? null)
                    || !preg_match('/^#[a-fA-F0-9]{6}$/D', $language['color'])) {
                    throw new InvalidArgumentException('Each language requires a name and a six-digit hex color.');
                }
                $size = $language['size'] ?? 0;
                if (!is_int($size) || $size < 0) {
                    throw new InvalidArgumentException('Language size must be a non-negative integer.');
                }
                $name = trim($language['name']);
                if (isset($excludedNames[mb_strtolower($name)])) {
                    continue;
                }
                $color = strtolower($language['color']);
                if (isset($counts[$name]) && $counts[$name]['color'] !== $color) {
                    throw new InvalidArgumentException("Conflicting colors for language: {$name}");
                }
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $counts[$name] ??= ['name' => $name, 'repositories' => 0, 'size' => 0, 'color' => $color];
                ++$counts[$name]['repositories'];
                $counts[$name]['size'] += $size;
            }
        }
        $ranking = array_values($counts);
        usort($ranking, static fn (array $a, array $b): int =>
            ($b['repositories'] <=> $a['repositories']) ?: ($b['size'] <=> $a['size']) ?: strcmp($a['name'], $b['name'])
        );

        return array_map(
            static fn (array $entry): array => ['name' => $entry['name'], 'repositories' => $entry['repositories'], 'color' => $entry['color']],
            array_slice($ranking, 0, 5),
        );
    }

    /**
     * @param list<array{name: string, color: string|null}> $entries The developer's own list, kept in order.
     * @param array<mixed> $repositories Colours GitHub (or the demo fixture) reported win over the one given.
     * @return list<array{name: string, repositories: null, color: string}>
     */
    public static function manual(array $entries, array $repositories): array
    {
        $reported = [];
        foreach ($repositories as $repository) {
            foreach (is_array($repository) ? ($repository['languages'] ?? []) : [] as $language) {
                // The fallback colour stands in for "GitHub reported none", so a colour given by the developer may still apply.
                if (is_array($language) && is_string($language['name'] ?? null) && is_string($language['color'] ?? null)
                    && preg_match('/^#[a-fA-F0-9]{6}$/D', $language['color']) === 1
                    && strtolower($language['color']) !== self::FALLBACK_COLOR) {
                    $reported[mb_strtolower(trim($language['name']))] ??= strtolower($language['color']);
                }
            }
        }

        return array_map(
            static fn (array $entry): array => [
                'name' => $entry['name'],
                'repositories' => null,
                'color' => $reported[mb_strtolower($entry['name'])] ?? $entry['color'] ?? self::FALLBACK_COLOR,
            ],
            $entries,
        );
    }
}
