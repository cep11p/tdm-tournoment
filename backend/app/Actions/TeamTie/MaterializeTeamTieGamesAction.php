<?php

namespace App\Actions\TeamTie;

use App\Actions\Game\CreateGameAction;
use App\Models\TeamTie;
use App\Models\TeamTieFormat;
use App\Models\TeamTieGame;
use Illuminate\Support\Collection;

final class MaterializeTeamTieGamesAction
{
    public function __construct(
        private readonly CreateGameAction $createGame,
    ) {}

    /**
     * @return Collection<int, TeamTieGame>
     */
    public function __invoke(TeamTie $teamTie, ?TeamTieFormat $format = null): Collection
    {
        $teamTie->loadMissing('competition');

        if ($format === null) {
            $format = TeamTieFormat::query()
                ->with('slots')
                ->findOrFail($teamTie->team_tie_format_id);
        } else {
            $format->loadMissing('slots');
        }

        $created = collect();

        foreach ($format->slots->sortBy('slot_order')->values() as $slot) {
            $game = ($this->createGame)([
                'competition_id' => $teamTie->competition_id,
                'entry1_id' => $teamTie->entry1_id,
                'entry2_id' => $teamTie->entry2_id,
                'winner_entry_id' => null,
                'group_id' => null,
                'bracket_id' => null,
                'round' => null,
                'group_round' => null,
                'group_match' => null,
                'bracket_round' => null,
                'bracket_match' => null,
            ]);

            $created->push(TeamTieGame::query()->create([
                'team_tie_id' => $teamTie->id,
                'game_id' => $game->id,
                'slot_order' => (int) $slot->slot_order,
                'modality' => $slot->modality,
            ]));
        }

        return $created;
    }
}
