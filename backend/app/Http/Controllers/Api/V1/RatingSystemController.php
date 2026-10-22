<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Rating\CreateRatingSystemAction;
use App\Actions\Rating\UpdateRatingSystemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rating\StoreRatingSystemRequest;
use App\Http\Requests\Rating\UpdateRatingSystemRequest;
use App\Http\Resources\Rating\RatingSystemResource;
use App\Models\RatingSystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RatingSystemController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $ratingSystems = RatingSystem::query()
            ->orderBy('name')
            ->get();

        return RatingSystemResource::collection($ratingSystems);
    }

    public function show(RatingSystem $ratingSystem): RatingSystemResource
    {
        return new RatingSystemResource($ratingSystem);
    }

    public function store(
        StoreRatingSystemRequest $request,
        CreateRatingSystemAction $createRatingSystem,
    ): JsonResponse {
        $ratingSystem = $createRatingSystem($request->validated());

        return (new RatingSystemResource($ratingSystem))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateRatingSystemRequest $request,
        RatingSystem $ratingSystem,
        UpdateRatingSystemAction $updateRatingSystem,
    ): RatingSystemResource {
        return new RatingSystemResource(
            $updateRatingSystem($ratingSystem, $request->validated()),
        );
    }
}
