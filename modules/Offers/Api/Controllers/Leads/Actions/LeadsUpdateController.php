<?php

namespace Modules\Offers\Api\Controllers\Leads\Actions;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Modules\Offers\Actions\Leads\UpdateLeadTrackerTaskCreateAction;
use Modules\Offers\Api\Requests\Leads\UpdateLeadRequest;
use Modules\Offers\Entities\Lead;

class LeadsUpdateController extends Controller
{

    /**
     * @param UpdateLeadRequest                 $request
     * @param UpdateLeadTrackerTaskCreateAction $action
     * @param Lead                              $lead
     *
     * @return \Illuminate\Http\Response
     */
    public function __invoke(
        UpdateLeadRequest $request,
        UpdateLeadTrackerTaskCreateAction $action,
        Lead $lead
    ): \Illuminate\Http\Response {

        $leadAction = 'update.' . $request->input('update_type');

        $action->execute(
            [$lead->id],
            $leadAction,
            $request->getParams(),
            Auth::user(),
            true
        );

        return Response::noContent();
    }
}
