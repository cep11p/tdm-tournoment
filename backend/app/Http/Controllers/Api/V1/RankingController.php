<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ranking\CreateRankingAction;
use App\Actions\Ranking\DeleteRankingAction;
use App\Actions\Ranking\UpdateRankingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ranking\StoreRankingRequest;
use App\Http\Requests\Ranking\UpdateRankingRequest;
use App\Http\Resources\Ranking\RankingPlayerHistoryResource;
use App\Http\Resources\Ranking\RankingResource;
use App\Http\Resources\Ranking\RankingStandingResource;
use App\Models\Player;
use App\Models\Ranking;
use App\Models\RankingStanding;
use App\Models\RankingTransaction;
use App\Support\Ranking\RankingStandingsTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RankingController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $rankings = Ranking::query()
            ->with('category')
            ->withExists('transactions')
            ->orderBy('competition_type')
            ->orderBy('season')
            ->orderBy('name')
            ->get();

        return RankingResource::collection($rankings);
    }

    public function show(Ranking $ranking): RankingResource
    {
        $ranking->load(['category', 'rules']);
        $ranking->loadExists('transactions');

        return new RankingResource($ranking);
    }

    public function store(
        StoreRankingRequest $request,
        CreateRankingAction $createRanking,
    ): JsonResponse {
        $ranking = $createRanking($request->validated());

        return (new RankingResource($ranking))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateRankingRequest $request,
        Ranking $ranking,
        UpdateRankingAction $updateRanking,
    ): RankingResource {
        return new RankingResource(
            $updateRanking($ranking, $request->validated()),
        );
    }

    public function destroy(
        Ranking $ranking,
        DeleteRankingAction $deleteRanking,
    ): Response {
        $deleteRanking($ranking);

        return response()->noContent();
    }

    public function standings(Ranking $ranking): AnonymousResourceCollection
    {
        $standings = $ranking->standings()
            ->with('player')
            ->get();

        $ranked = RankingStandingsTable::ranked($standings);

        return RankingStandingResource::collection(collect($ranked));
    }

    public function playerTransactions(Ranking $ranking, Player $player): RankingPlayerHistoryResource
    {
        $transactions = RankingTransaction::query()
            ->where('ranking_transactions.ranking_id', $ranking->id)
            ->where('ranking_transactions.player_id', $player->id)
            ->join('competitions', 'competitions.id', '=', 'ranking_transactions.competition_id')
            ->join('tournaments', 'tournaments.id', '=', 'competitions.tournament_id')
            ->with(['competition.tournament'])
            ->orderByDesc('tournaments.start_date')
            ->orderByDesc('ranking_transactions.competition_id')
            ->select('ranking_transactions.*')
            ->get();

        $standing = RankingStanding::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $player->id)
            ->first();

        return new RankingPlayerHistoryResource([
            'ranking' => $ranking,
            'player' => $player,
            'standing' => $standing,
            'transactions' => $transactions,
        ]);
    }
}
