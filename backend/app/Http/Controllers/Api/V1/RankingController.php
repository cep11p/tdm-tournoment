<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Ranking\RankingResource;
use App\Http\Resources\Ranking\RankingStandingResource;
use App\Models\Ranking;
use App\Support\Ranking\RankingStandingsTable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RankingController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $rankings = Ranking::query()
            ->with('category')
            ->orderBy('competition_type')
            ->orderBy('season')
            ->orderBy('name')
            ->get();

        return RankingResource::collection($rankings);
    }

    public function show(Ranking $ranking): RankingResource
    {
        $ranking->load(['category', 'rules']);

        return new RankingResource($ranking);
    }

    public function standings(Ranking $ranking): AnonymousResourceCollection
    {
        $standings = $ranking->standings()
            ->with('player')
            ->get();

        $ranked = RankingStandingsTable::ranked($standings);

        return RankingStandingResource::collection(collect($ranked));
    }
}
