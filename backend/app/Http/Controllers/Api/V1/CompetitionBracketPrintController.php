<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bracket\BuildPrintBracketAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Bracket\PrintBracketResource;
use App\Models\Competition;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CompetitionBracketPrintController extends Controller
{
    public function __invoke(
        Competition $competition,
        BuildPrintBracketAction $buildPrintBracket,
    ): PrintBracketResource|JsonResponse {
        try {
            return new PrintBracketResource($buildPrintBracket($competition));
        } catch (NotFoundHttpException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }
    }
}
