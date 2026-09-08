<?php

namespace App\Actions\Bracket;

use App\Data\Bracket\PrintBracketData;
use App\Models\BracketEntryOrigin;
use App\Models\Competition;
use App\Models\TeamTie;
use App\Support\Bracket\BracketPrintStructureBuilder;
use App\Support\Bracket\PrintBracketMatchSnapshot;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BuildPrintBracketAction
{
    public const MISSING_BRACKET_MESSAGE = 'La competencia no tiene un cuadro eliminatorio.';

    public function __construct(
        private readonly BracketPrintStructureBuilder $builder,
    ) {}

    public function __invoke(Competition $competition): PrintBracketData
    {
        $competition->loadMissing('tournament');

        $relations = $competition->isTeam()
            ? array_map(
                fn (string $relation): string => 'teamTies.'.$relation,
                TeamTie::DISPLAY_RELATIONS,
            )
            : array_map(
                fn (string $relation): string => 'games.'.$relation,
                [
                    'entry1.members.player:id,first_name,last_name,nickname',
                    'entry2.members.player:id,first_name,last_name,nickname',
                    'winnerEntry.members.player:id,first_name,last_name,nickname',
                ],
            );

        $bracket = $competition->brackets()
            ->with(['entryOrigins', ...$relations])
            ->first();

        if ($bracket === null) {
            throw new NotFoundHttpException(self::MISSING_BRACKET_MESSAGE);
        }

        $origins = $bracket->entryOrigins->keyBy(
            fn (BracketEntryOrigin $origin): int => (int) $origin->competition_entry_id,
        );

        $snapshots = $competition->isTeam()
            ? PrintBracketMatchSnapshot::fromTeamTies($bracket->teamTies, $origins)
            : PrintBracketMatchSnapshot::fromGames($bracket->games, $origins);

        return $this->builder->build(
            $bracket,
            $competition,
            $snapshots,
            [
                'id' => (int) $competition->tournament_id,
                'name' => (string) ($competition->tournament?->name ?? ''),
            ],
        );
    }
}
