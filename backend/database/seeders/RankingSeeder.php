<?php

namespace Database\Seeders;

use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\Ranking;
use App\Models\RankingRule;
use Illuminate\Database\Seeder;

class RankingSeeder extends Seeder
{
    public const SINGLES_NAME = 'Ranking Singles 2026';

    public const DOUBLES_NAME = 'Ranking Dobles 2026';

    public const SEASON = '2026';

    public function run(): void
    {
        $this->seedRanking(self::SINGLES_NAME, CompetitionType::Singles);
        $this->seedRanking(self::DOUBLES_NAME, CompetitionType::Doubles);
    }

    private function seedRanking(string $name, CompetitionType $type): void
    {
        $ranking = Ranking::query()->where('name', $name)->first();

        if ($ranking === null) {
            $ranking = Ranking::query()->create([
                'name' => $name,
                'competition_type' => $type,
                'category_id' => null,
                'season' => self::SEASON,
                'active' => true,
                'starts_at' => null,
                'ends_at' => null,
            ]);
        }

        if ($ranking->rules()->exists()) {
            return;
        }

        foreach (self::defaultRules() as $index => $rule) {
            RankingRule::query()->create([
                'ranking_id' => $ranking->id,
                'name' => $rule['name'],
                'source' => $rule['source'],
                'position' => $rule['position'],
                'points' => $rule['points'],
                'priority' => 100 - $index,
                'active' => true,
            ]);
        }
    }

    /**
     * @return list<array{name: string, source: CompetitionFinalStandingSource, position: int|null, points: int}>
     */
    public static function defaultRules(): array
    {
        return [
            [
                'name' => 'Campeón',
                'source' => CompetitionFinalStandingSource::Final,
                'position' => 1,
                'points' => 100,
            ],
            [
                'name' => 'Subcampeón',
                'source' => CompetitionFinalStandingSource::Final,
                'position' => 2,
                'points' => 70,
            ],
            [
                'name' => 'Tercer puesto',
                'source' => CompetitionFinalStandingSource::ThirdPlacePlayoff,
                'position' => 3,
                'points' => 50,
            ],
            [
                'name' => 'Cuarto puesto',
                'source' => CompetitionFinalStandingSource::ThirdPlacePlayoff,
                'position' => 4,
                'points' => 40,
            ],
            [
                'name' => 'Semifinal',
                'source' => CompetitionFinalStandingSource::Semifinal,
                'position' => null,
                'points' => 45,
            ],
            [
                'name' => 'Cuartos de final',
                'source' => CompetitionFinalStandingSource::Quarterfinal,
                'position' => null,
                'points' => 25,
            ],
            [
                'name' => 'Octavos de final',
                'source' => CompetitionFinalStandingSource::RoundOf16,
                'position' => null,
                'points' => 15,
            ],
            [
                'name' => 'Dieciseisavos de final',
                'source' => CompetitionFinalStandingSource::RoundOf32,
                'position' => null,
                'points' => 8,
            ],
            [
                'name' => 'Play-in',
                'source' => CompetitionFinalStandingSource::PlayIn,
                'position' => null,
                'points' => 4,
            ],
            [
                'name' => 'Fase de grupos',
                'source' => CompetitionFinalStandingSource::GroupStage,
                'position' => null,
                'points' => 2,
            ],
        ];
    }
}
