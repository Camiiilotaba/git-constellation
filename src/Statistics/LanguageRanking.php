<?php

declare(strict_types=1);

namespace GitConstellation\Statistics;

use InvalidArgumentException;

/** Counts each detected language once per eligible public repository, including secondary languages. */
final class LanguageRanking
{
    /**
     * @param array<mixed> $repositories Simulated repository metadata, never repository source code.
     * @return list<array{name: string, repositories: int, color: string}>
     */
    public static function calculate(array $repositories): array
    {
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
                $name = trim($language['name']);
                $color = strtolower($language['color']);
                if (isset($counts[$name]) && $counts[$name]['color'] !== $color) {
                    throw new InvalidArgumentException("Conflicting colors for language: {$name}");
                }
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $counts[$name] ??= ['name' => $name, 'repositories' => 0, 'color' => $color];
                ++$counts[$name]['repositories'];
            }
        }
        $ranking = array_values($counts);
        usort($ranking, static fn (array $a, array $b): int =>
            ($b['repositories'] <=> $a['repositories']) ?: strcmp($a['name'], $b['name'])
        );

        return array_slice($ranking, 0, 5);
    }
}
