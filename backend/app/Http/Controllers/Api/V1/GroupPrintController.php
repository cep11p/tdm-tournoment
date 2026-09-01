<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Group\BuildPrintGroupSheetAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Group\PrintGroupSheetResource;
use App\Models\Group;

class GroupPrintController extends Controller
{
    public function show(
        Group $group,
        BuildPrintGroupSheetAction $buildPrintGroupSheet,
    ): PrintGroupSheetResource {
        return new PrintGroupSheetResource($buildPrintGroupSheet($group));
    }
}
