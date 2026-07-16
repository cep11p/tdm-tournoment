<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Club\ClubResource;
use App\Models\Club;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClubController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $clubs = Club::query()
            ->active()
            ->orderBy('name')
            ->get();

        return ClubResource::collection($clubs);
    }
}
