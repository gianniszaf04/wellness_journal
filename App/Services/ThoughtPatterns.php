<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\ThoughtRecord;

class ThoughtPatterns
{
    public const MIN_PAGES = 5;
    public const MIN_TRAP_PAGES = 2;
    private const MAX_TRAPS = 3;

    public static function from(array $records): ?array
    {
        $pages = array_values(array_filter($records, static fn (array $r): bool => !(int) $r['crisis']));
        if (count($pages) < self::MIN_PAGES) {
            return null;
        }

        $byTrap = [];
        foreach ($pages as $page) {
            if (self::counts($page)) {
                $byTrap[$page['observed_type']][] = $page;
            }
        }
        $byTrap = array_filter($byTrap, static fn (array $list): bool => count($list) >= self::MIN_TRAP_PAGES);
        uasort($byTrap, static fn (array $a, array $b): int => count($b) <=> count($a));

        $traps = [];
        foreach (array_slice($byTrap, 0, self::MAX_TRAPS, true) as $type => $list) {
            $traps[] = [
                'type' => $type,
                'quoted' => ThinkingTraps::describe($type)['quoted'],
                'pages' => count($list),
                'emotions' => self::usualEmotions($list),
            ];
        }

        $strongest = array_filter(
            array_map(static fn (array $page): ?array => ThinkingTraps::strongest($page['emotions'] ?? []), $pages),
            static fn (?array $e): bool => isset($e['before'], $e['after'])
        );
        $intensity = $strongest === [] ? null : [
            'before' => (int) round(array_sum(array_column($strongest, 'before')) / count($strongest)),
            'after' => (int) round(array_sum(array_column($strongest, 'after')) / count($strongest)),
        ];

        return ['pages' => count($pages), 'traps' => $traps, 'intensity' => $intensity];
    }

    private static function counts(array $page): bool
    {
        return $page['response'] === ThoughtRecord::RESPONSE_YES && $page['observed_type'] !== null
            && ThinkingTraps::exists((string) $page['observed_type'])
            && ($page['observation_source'] === ThoughtRecord::SOURCE_ASKED || trim((string) $page['reframe']) !== '');
    }

    private static function usualEmotions(array $pages): array
    {
        $counts = [];
        foreach ($pages as $page) {
            foreach (array_unique(array_column($page['emotions'] ?? [], 'emotion')) as $name) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }
        arsort($counts);
        $usual = array_keys(array_filter($counts, static fn (int $n): bool => $n * 2 >= count($pages)));
        return array_values(array_filter(array_map(
            static fn (string $name): ?string => ThinkingTraps::EMOTIONS[$name]['with'] ?? null,
            $usual
        )));
    }
}
