<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Rating\CreateRatingRuleVersionAction;
use App\Actions\Rating\PublishRatingRuleVersionAction;
use App\Actions\Rating\UpdateRatingRuleVersionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rating\StoreRatingRuleVersionRequest;
use App\Http\Requests\Rating\UpdateRatingRuleVersionRequest;
use App\Http\Resources\Rating\RatingRuleVersionResource;
use App\Models\RatingRuleVersion;
use App\Models\RatingSystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RatingRuleVersionController extends Controller
{
    public function index(RatingSystem $ratingSystem): AnonymousResourceCollection
    {
        return RatingRuleVersionResource::collection(
            $ratingSystem->ratingRuleVersions()->get(),
        );
    }

    public function show(RatingSystem $ratingSystem, RatingRuleVersion $ratingRuleVersion): RatingRuleVersionResource
    {
        return new RatingRuleVersionResource($ratingRuleVersion);
    }

    public function store(
        StoreRatingRuleVersionRequest $request,
        RatingSystem $ratingSystem,
        CreateRatingRuleVersionAction $createRatingRuleVersion,
    ): JsonResponse {
        $version = $createRatingRuleVersion($ratingSystem, $request->validated());

        return (new RatingRuleVersionResource($version))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateRatingRuleVersionRequest $request,
        RatingSystem $ratingSystem,
        RatingRuleVersion $ratingRuleVersion,
        UpdateRatingRuleVersionAction $updateRatingRuleVersion,
    ): RatingRuleVersionResource {
        return new RatingRuleVersionResource(
            $updateRatingRuleVersion($ratingRuleVersion, $request->validated()),
        );
    }

    public function publish(
        RatingSystem $ratingSystem,
        RatingRuleVersion $ratingRuleVersion,
        PublishRatingRuleVersionAction $publishRatingRuleVersion,
    ): RatingRuleVersionResource {
        return new RatingRuleVersionResource(
            $publishRatingRuleVersion($ratingRuleVersion),
        );
    }
}
