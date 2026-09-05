<?php

namespace App\Support\Ranking;

use App\Enums\CompetitionFinalStandingSource;

final class RankingRuleCatalog
{
    public const CHAMPION = 'champion';

    public const RUNNER_UP = 'runner_up';

    public const THIRD_PLACE = 'third_place';

    public const FOURTH_PLACE = 'fourth_place';

    public const SEMIFINAL = 'semifinal';

    public const QUARTERFINAL = 'quarterfinal';

    public const ROUND_OF_16 = 'round_of_16';

    public const ROUND_OF_32 = 'round_of_32';

    public const PLAY_IN = 'play_in';

    public const GROUP_STAGE = 'group_stage';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::definitions(), 'key');
    }

    /**
     * @return list<array{key: string, name: string, source: CompetitionFinalStandingSource, position: int|null}>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => self::CHAMPION,
                'name' => 'Campeón',
                'source' => CompetitionFinalStandingSource::Final,
                'position' => 1,
            ],
            [
                'key' => self::RUNNER_UP,
                'name' => 'Subcampeón',
                'source' => CompetitionFinalStandingSource::Final,
                'position' => 2,
            ],
            [
                'key' => self::THIRD_PLACE,
                'name' => 'Tercer puesto',
                'source' => CompetitionFinalStandingSource::ThirdPlacePlayoff,
                'position' => 3,
            ],
            [
                'key' => self::FOURTH_PLACE,
                'name' => 'Cuarto puesto',
                'source' => CompetitionFinalStandingSource::ThirdPlacePlayoff,
                'position' => 4,
            ],
            [
                'key' => self::SEMIFINAL,
                'name' => 'Semifinal',
                'source' => CompetitionFinalStandingSource::Semifinal,
                'position' => null,
            ],
            [
                'key' => self::QUARTERFINAL,
                'name' => 'Cuartos de final',
                'source' => CompetitionFinalStandingSource::Quarterfinal,
                'position' => null,
            ],
            [
                'key' => self::ROUND_OF_16,
                'name' => 'Octavos de final',
                'source' => CompetitionFinalStandingSource::RoundOf16,
                'position' => null,
            ],
            [
                'key' => self::ROUND_OF_32,
                'name' => 'Dieciseisavos',
                'source' => CompetitionFinalStandingSource::RoundOf32,
                'position' => null,
            ],
            [
                'key' => self::PLAY_IN,
                'name' => 'Play-in',
                'source' => CompetitionFinalStandingSource::PlayIn,
                'position' => null,
            ],
            [
                'key' => self::GROUP_STAGE,
                'name' => 'Fase de grupos',
                'source' => CompetitionFinalStandingSource::GroupStage,
                'position' => null,
            ],
        ];
    }

    /**
     * @return array{key: string, name: string, source: CompetitionFinalStandingSource, position: int|null}|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::definitions() as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        return null;
    }

    public static function keyFor(CompetitionFinalStandingSource|string|null $source, mixed $position): ?string
    {
        $resolved = $source instanceof CompetitionFinalStandingSource
            ? $source
            : CompetitionFinalStandingSource::tryFrom((string) $source);

        if ($resolved === null) {
            return null;
        }

        $normalizedPosition = $position === null || $position === '' ? null : (int) $position;

        foreach (self::definitions() as $definition) {
            if ($definition['source'] === $resolved && $definition['position'] === $normalizedPosition) {
                return $definition['key'];
            }
        }

        return null;
    }

    public static function positionKey(?int $position): int
    {
        return $position === null ? 0 : $position;
    }
}
