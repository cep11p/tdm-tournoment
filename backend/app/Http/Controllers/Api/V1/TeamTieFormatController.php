<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamTie\TeamTieFormatResource;
use App\Models\TeamTieFormat;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeamTieFormatController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $formats = TeamTieFormat::query()
            ->where('active', true)
            ->with(['slots' => fn ($query) => $query->orderBy('slot_order')])
            ->orderBy('name')
            ->get();

        return TeamTieFormatResource::collection($formats);
    }

    public function show(TeamTieFormat $teamTieFormat): TeamTieFormatResource
    {
        $teamTieFormat->load(['slots' => fn ($query) => $query->orderBy('slot_order')]);

        return new TeamTieFormatResource($teamTieFormat);
    }
}
