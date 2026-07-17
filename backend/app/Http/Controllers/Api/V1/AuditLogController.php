<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Audit\ListAuditLogsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\IndexAuditLogRequest;
use App\Http\Resources\Audit\AuditLogDetailResource;
use App\Http\Resources\Audit\AuditLogResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function index(
        IndexAuditLogRequest $request,
        ListAuditLogsAction $listAuditLogs,
    ): AnonymousResourceCollection {
        return AuditLogResource::collection(
            $listAuditLogs($request->validated()),
        );
    }

    public function show(Activity $activity): AuditLogDetailResource
    {
        $activity->load(['causer', 'subject']);

        return new AuditLogDetailResource($activity);
    }
}
