<?php

namespace Modules\Offers\Api\Controllers\Leads;

use App\Components\Grid\Grid;
use App\Components\Grid\GridRequest;
use App\Components\Grid\Responses\ListResponse;
use App\Components\Tracker\Tracker;
use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Offers\Api\Providers\LeadsListProvider;
use Modules\Offers\Api\Requests\Leads\LeadPostbackRequest;
use Modules\Offers\Api\Resources\Leads\LeadActionsLog\LeadActionLogListCollection;
use Modules\Offers\Api\Resources\Leads\LeadAffiliateViewResource;
use Modules\Offers\Api\Resources\Leads\LeadMerchantViewResource;
use Modules\Offers\Api\Resources\Leads\LeadNetworkViewResource;
use Modules\Offers\Api\Resources\Leads\LeadsListCollection;
use Modules\Offers\Components\Leads\LeadsService;
use Modules\Offers\Entities\Lead;
use Modules\Users\Entities\User;
use Throwable;

class LeadsController extends Controller
{
    /**
     * @param GridRequest       $request
     * @param LeadsListProvider $provider
     *
     * @return ListResponse
     * @throws ValidationException
     */
    public function grid(GridRequest $request, LeadsListProvider $provider)
    {
        $grid = new Grid($provider, LeadsListCollection::class);

        return $grid->response($request);
    }

    /**
     * @param Lead $lead
     *
     * @return JsonResponse
     * @throws AuthorizationException
     * @throws Exception
     */
    public function show(Lead $lead)
    {
        $this->authorize('view', $lead);

        return $this->singleLeadResponse($lead);
    }

    /**
     * @param Lead $lead
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function history(Lead $lead)
    {
        $this->authorize('history', $lead);

        return $this->leadHistoryResponse($lead);
    }

    public function postback(
        LeadPostbackRequest $request,
        LeadsService $leadService,
    ) {
        $routeName = $request->route()->getName();

        try {
            $leadService->create($request);

            $response = response()
                ->json(['success' => true])
                ->setStatusCode(200);

            Tracker::logger()
                ->info($routeName, [
                    'REQUEST'  => $request->toArray(),
                    'RESPONSE' => $response->getData(true),
                ]);
        } catch (Throwable $exception) {
            $response = response()
                ->json([
                    'success' => false,
                    'message' => 'The given data was unexpected.',
                ])
                ->setStatusCode(500);

            Tracker::logger()
                ->error("{$routeName} (Unexpected error)", [
                    'REQUEST'   => $request->toArray(),
                    'RESPONSE'  => $response->getData(true),
                    'EXCEPTION' => $exception,
                ]);

            if (app()->bound('sentry')) {
                app('sentry')->captureException($exception);
            }

            throw new HttpResponseException($response);
        }

        return $response;
    }

    /**
     * @param Lead      $lead
     * @param User|null $user
     *
     * @return JsonResponse
     * @throws Exception
     */
    protected function singleLeadResponse(Lead $lead, ?User $user = null)
    {
        if (is_null($user)) {
            $user = Auth::user();
        }

        $lead->loadMissing([
            'network',
            'offer',
            'trackingPivot',
            'offerRate',
            'externalData',
            'promoToolCode.promo.textDescriptions'
        ]);

        switch ($user->role) {
            case User::ROLE_AFFILIATE:
                $lead->loadMissing(['campaign']);
                $resource = new LeadAffiliateViewResource($lead);
                break;

            case User::ROLE_MERCHANT:
                $resource = new LeadMerchantViewResource($lead);
                break;

            case User::ROLE_NETWORK:
            case User::ROLE_MANAGER_ADMIN:
            case User::ROLE_MANAGER_AFFILIATE:
            case User::ROLE_MANAGER_MERCHANT:
                $lead->loadMissing(['campaign', 'affiliate']);
                $resource = new LeadNetworkViewResource($lead);
                break;

            default:
                throw new Exception('Not implemented');
        }

        return $resource->response();
    }

    protected function leadHistoryResponse(Lead $lead)
    {
        $lead->loadMissing([
            'actionsLog' => function (HasMany $query) {
                $query
                    ->with(['user'])
                    ->latest('created_at');
            },
        ]);

        $resource = new LeadActionLogListCollection($lead->actionsLog);

        return $resource->response()->setStatusCode(200);
    }
}
