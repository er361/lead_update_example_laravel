<?php

namespace Modules\Offers\Components\Leads;

use App\Components\UUID;
use App\Entities\Currency;
use App\Exceptions\DatabaseErrorException;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Offers\Api\Requests\Leads\LeadPostbackRequest;
use Modules\Offers\Entities\Campaign;
use Modules\Offers\Entities\Lead;
use Modules\Offers\Entities\OfferRate;
use Modules\Offers\Entities\PromoTool;
use Modules\Offers\Entities\PromoWebsite;
use Modules\Offers\Exceptions\Leads\LeadActionsService\BadLeadStatusException;
use Modules\Offers\Exceptions\Leads\LeadActionsService\LeadPaymentTypeIsNotPercentException;
use Throwable;

class LeadsService
{
    protected LeadActionsService $leadActionsService;

    public function __construct(LeadActionsService $leadActionsService)
    {
        $this->leadActionsService = $leadActionsService;
    }

    /**
     * @param int    $campaignId
     * @param int    $offerRateId
     * @param string $payload
     * @param int    $createdAt
     * @param null   $paymentInitial
     *
     * @return Lead
     * @throws DatabaseErrorException
     * @throws Exception
     */
    public function createFake(
        int $campaignId,
        int $offerRateId,
        string $payload,
        int $createdAt,
        $paymentInitial = null
    ): Lead {
        $lead = new Lead(compact('payload'));

        $campaign = Campaign::findOrFail($campaignId);

        $websiteParams = [
            'name' => 'a website for a fake lead',
            'link' => 'https://google.com',
        ];

        $promoWebsite = PromoWebsite::create($websiteParams);
        $promoTool = PromoTool::create([
            PromoTool::MORPH_TYPE_FIELD => PromoTool::TYPE_WEBSITE,
            PromoTool::MORPH_ID_FIELD   => $promoWebsite->id,
            'offer_id'                  => $campaign->offer_id,
            'visibility'                => PromoTool::VISIBILITY_PUBLIC,
        ]);

        $lead->tracker_id = UUID::v4();
        $lead->currency = Currency::US_DOLLAR;
        $lead->session_id = UUID::v4();
        $lead->client_id = UUID::v4();
        $lead->request_id = rand(0, 1000);
        $lead->network_id = $campaign->offer->network_id;
        $lead->offer_id = $campaign->offer_id;
        $lead->campaign_id = $campaignId;
        $lead->offer_rate_id = $offerRateId;
        $lead->promo_tool_id = $promoTool->id;
        $lead->created_at = $createdAt;
        $lead->affiliate_id = $campaign->affiliate_id;
        $lead->merchant_id = $campaign->offer->merchant_id;
        $lead->timestamp = new Carbon();

        /** @var OfferRate $offerRate */
        $offerRate = OfferRate::query()->find($offerRateId);
        if (! $offerRate) {
            throw new ModelNotFoundException();
        }

        $leadRewards = $offerRate->calculateLeadRewards($paymentInitial);

        $lead->merchant_payment = $leadRewards['merchant_payment'];
        $lead->affiliate_profit = $leadRewards['affiliate_profit'];
        $lead->network_profit = $leadRewards['network_profit'];

        if (! $lead->save()) {
            throw new DatabaseErrorException();
        }

        return $lead;
    }

    /**
     * @param LeadPostbackRequest $request
     *
     * @return Lead
     * @throws Throwable
     */
    public function create(LeadPostbackRequest $request): Lead
    {
        // $this->validateLeadRelations($lead);

        return DB::transaction(function () use ($request) {
            $lead = $this->getLeadModel($request);
            $oldStatus = $lead->getOriginal('status');
            $oldPayment = $lead->getOriginal('merchant_payment');

            if (! $lead->save()) {
                throw new DatabaseErrorException();
            }

            $this->syncExternalData($lead, $request);

            if ($oldStatus !== $request->getStatus()) {
                if ($lead->wasRecentlyCreated) {
                    $oldPayment = null;
                }
                $this->tryToChangeLeadStatus($request, $lead, $oldPayment);
            } else {
                if (in_array($lead->trackingPivot?->task?->action, Lead::ALLOWED_UPDATE_ACTION)) {
                    $this->updateLead($lead, $request);
                }
            }

            return $lead;
        });
    }

    public function updateLead(Lead $lead, LeadPostbackRequest $request)
    {
        $oldPayment = $lead->getOriginal('merchant_payment');
        $newPayment = $this->getNewPayment($request);

        if ($oldPayment !== $newPayment) {
            $this->leadActionsService->update($lead, $newPayment, $oldPayment);
        }
    }

    /**
     * @param LeadPostbackRequest $request
     * @param Lead                $lead
     * @param int|null            $oldPayment
     *
     * @throws BadLeadStatusException
     * @throws DatabaseErrorException
     * @throws LeadPaymentTypeIsNotPercentException
     */
    protected function tryToChangeLeadStatus(
        LeadPostbackRequest $request,
        Lead $lead,
        int $oldPayment = null
    ): void {
        $newStatus = $request->getStatus();

        switch ($newStatus) {
            case Lead::STATUS_APPROVED:
                $newPayment = $request->getPrice();
                if (is_null($newPayment)) {
                    $newPayment = $request->getMerchantPayment();
                }
                if (isset($oldPayment) && ($oldPayment !== $newPayment)) {
                    $this->leadActionsService->approve(
                        $lead,
                        $lead->merchant->owner,
                        formatBalanceOutput($newPayment),
                        'Auto changing cost by postback'
                    );
                } else {
                    $this->leadActionsService->approve($lead, $lead->merchant->owner);
                }
                return;

            case Lead::STATUS_REJECTED:
                $this->leadActionsService->reject(
                    $lead,
                    $lead->merchant->owner,
                    'Auto rejecting lead by postback',
                );
                return;
        }
    }

    protected function getLeadModel(LeadPostbackRequest $request): Lead
    {
        $lead = Lead::query()
            ->where('tracker_id', '=', $request->getTrackerId())
            ->first();

        if (! $lead) {
            $lead = new Lead();
            $lead->tracker_id = $request->getTrackerId();
            $lead->payload = $request->getPayload();
            $lead->network_profit = $request->getNetworkProfit();
            $lead->affiliate_profit = $request->getAffiliateProfit();
            $lead->merchant_payment = $request->getMerchantPayment();
            $lead->currency = $request->getCurrency();
            $lead->notice = $request->getNotice();
            $lead->click_id = $request->getClickId();
            $lead->session_id = $request->getSessionId();
            $lead->client_id = $request->getClientId();
            $lead->request_id = $request->getRequestId();
            $lead->network_id = $request->getNetworkId();
            $lead->offer_id = $request->getOfferId();
            $lead->campaign_id = $request->getCampaignId();
            $lead->offer_rate_id = $request->getOfferRateId();
            $lead->promo_tool_id = $request->getPromoToolId();
            $lead->affiliate_id = $request->getAffiliateId();
            $lead->merchant_id = $request->getMerchantId();
            $lead->timestamp = $request->getTimestamp();
            $lead->customer_id = $request->getCustomerId();
            $lead->template_data = $request->getTemplateData();
            $lead->promo_tool_code_id = $request->getPromoToolCodeId();
            $lead->created_at = Carbon::now();
        }

        if ($lead && $lead->status === Lead::STATUS_HOLD) {
            $lead->payload = $request->getPayload();
            $lead->notice = $request->getNotice();
        }

        return $lead;
    }

    protected function syncExternalData(Lead $lead, LeadPostbackRequest $request): void
    {
        if ($lead->externalData()->exists()) {
            return;
        }

        if (! $externalDataPayload = $request->getExternalData()) {
            return;
        }

        $lead->externalData()->create([
            'payload' => $externalDataPayload,
        ]);
    }

    /**
     * @param Lead $lead
     *
     * @throws DatabaseErrorException
     */
    protected function validateLeadRelations(Lead $lead): void
    {
        $campaign = Campaign::find($lead->campaign_id);
        $offerRate = OfferRate::find($lead->offer_rate_id);
        $promoTool = PromoTool::find($lead->promo_tool_id);

        $campaignIsInOffer = $campaign->offer_id === $lead->offer_id;
        $offerIsInNetwork = $campaign->offer->network_id === $lead->network_id;
        $offerRateIsInOffer = $offerRate->offer_id === $lead->offer_id;
        $promoToolIsInOffer = $promoTool->offer_id === $lead->offer_id;
        $affiliateIsOwner = $campaign->affiliate_id === $lead->affiliate_id;
        $merchantIsOwner = $campaign->offer->merchant_id === $lead->merchant_id;
        $currencyMatches = $campaign->offer->currency === $lead->currency;

        if (! $campaignIsInOffer
            || ! $offerIsInNetwork
            || ! $offerRateIsInOffer
            || ! $promoToolIsInOffer
            || ! $affiliateIsOwner
            || ! $merchantIsOwner
            || ! $currencyMatches
        ) {
            throw new DatabaseErrorException();
        }
    }

    /**
     * @return mixed
     */
    protected function getNewPayment(LeadPostbackRequest $request)
    {
        $newPayment = $request->getPrice();

        if (is_null($newPayment)) {
            $newPayment = $request->getMerchantPayment();
        }
        return $newPayment;
    }
}
