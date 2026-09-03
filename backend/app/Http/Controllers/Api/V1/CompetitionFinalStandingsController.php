<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompetitionFinalStanding\CompetitionFinalStandingResource;
use App\Models\Competition;
use App\Support\Competition\CompetitionStatusResolver;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CompetitionFinalStandingsController extends Controller
{
    public const UNAVAILABLE_MESSAGE = 'La clasificación final todavía no está disponible.';

    public const MISSING_SNAPSHOT_MESSAGE = 'La clasificación final no está consolidada.';

    public function index(Competition $competition): AnonymousResourceCollection
    {
        $status = CompetitionStatusResolver::resolve($competition);

        if ($status['code'] !== 'completed') {
            throw ValidationException::withMessages([
                'competition' => [self::UNAVAILABLE_MESSAGE],
            ]);
        }

        $standings = $competition->finalStandings()
            ->orderBy('position')
            ->orderBy('display_name_snapshot')
            ->orderBy('competition_entry_id')
            ->get();

        if ($standings->isEmpty()) {
            throw new NotFoundHttpException(self::MISSING_SNAPSHOT_MESSAGE);
        }

        return CompetitionFinalStandingResource::collection($standings);
    }
}
