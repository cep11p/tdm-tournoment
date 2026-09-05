<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ranking\CreateRankingRuleAction;
use App\Actions\Ranking\DeleteRankingRuleAction;
use App\Actions\Ranking\UpdateRankingRuleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ranking\StoreRankingRuleRequest;
use App\Http\Requests\Ranking\UpdateRankingRuleRequest;
use App\Http\Resources\Ranking\RankingRuleResource;
use App\Models\Ranking;
use App\Models\RankingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class RankingRuleController extends Controller
{
    public function store(
        StoreRankingRuleRequest $request,
        Ranking $ranking,
        CreateRankingRuleAction $createRankingRule,
    ): JsonResponse {
        $rule = $createRankingRule($ranking, $request->validated());

        return (new RankingRuleResource($rule))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateRankingRuleRequest $request,
        Ranking $ranking,
        RankingRule $rule,
        UpdateRankingRuleAction $updateRankingRule,
    ): RankingRuleResource {
        return new RankingRuleResource(
            $updateRankingRule($ranking, $rule, $request->validated()),
        );
    }

    public function destroy(
        Ranking $ranking,
        RankingRule $rule,
        DeleteRankingRuleAction $deleteRankingRule,
    ): Response {
        $deleteRankingRule($ranking, $rule);

        return response()->noContent();
    }
}
