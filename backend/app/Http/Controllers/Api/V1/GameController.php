<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Game\CreateGameAction;
use App\Actions\Game\DeleteGameAction;
use App\Actions\Game\RecordGameSetAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\StoreGameRequest;
use App\Http\Requests\Game\StoreGameSetRequest;
use App\Http\Resources\Game\GameResource;
use App\Models\Competition;
use App\Models\Game;
use App\Support\Game\GameFormatResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class GameController extends Controller
{
    private const GAME_RELATIONS = [
        'player1:id,first_name,last_name,nickname',
        'player2:id,first_name,last_name,nickname',
        'winner:id,first_name,last_name,nickname',
        'sets',
    ];

    public function index(Competition $competition): AnonymousResourceCollection
    {
        $games = $competition->games()
            ->with(self::GAME_RELATIONS)
            ->orderByRaw('group_round IS NULL')
            ->orderBy('group_round')
            ->orderBy('group_match')
            ->orderBy('id')
            ->get();

        return GameResource::collection($games);
    }

    public function show(Game $game): GameResource
    {
        $game->load(self::GAME_RELATIONS);

        return new GameResource($game);
    }

    public function store(
        StoreGameRequest $request,
        Competition $competition,
        CreateGameAction $createGame
    ): JsonResponse {
        $matchFormat = GameFormatResolver::resolveForGroup($competition);

        $game = $createGame([
            ...$request->validated(),
            'competition_id' => $competition->id,
            'best_of' => $matchFormat['best_of'],
            'sets_to_win' => $matchFormat['sets_to_win'],
        ])->load(self::GAME_RELATIONS);

        return (new GameResource($game))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function storeSet(
        StoreGameSetRequest $request,
        Game $game,
        RecordGameSetAction $recordGameSet
    ): GameResource {
        $game = $recordGameSet($game, $request->validated());

        return new GameResource($game);
    }

    public function destroy(Game $game, DeleteGameAction $deleteGame): Response
    {
        $deleteGame($game);

        return response()->noContent();
    }
}
