<?php

namespace Tests\Unit\Offers\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\Accounts\Entities\Operation;
use Modules\Networks\Entities\NetworkAccount;
use Modules\Offers\Entities\Lead;
use Modules\Offers\Entities\OfferRate;
use Modules\Offers\Events\Leads\LeadActions\LeadApproved;
use Modules\Offers\Events\Leads\LeadActions\LeadUpdated;
use Modules\Offers\Listeners\LeadApproveMoneyTransactionsListener;
use Modules\Offers\Listeners\LeadUpdateMoneyTransactionsListener;
use Tests\TestCase;
use Throwable;

class LeadUpdateMoneyTransactionsListenerTest extends TestCase
{
    protected LeadUpdateMoneyTransactionsListener $listener;

    public function calculationsDataProvider()
    {
        return [
            [
                [OfferRate::PAYMENT_TYPE_PERCENT, 10, 10.5],
                //lead params
                [
                    'merchant_payment' => formatBalanceInput(20),
                    'affiliate_profit' => formatBalanceInput(17.9),
                    'network_profit'   => formatBalanceInput(2.1),
                ],
                //update lead params
                [
                    'merchant_payment' => formatBalanceInput(22),
                    'affiliate_profit' => formatBalanceInput(12.8),
                    'network_profit'   => formatBalanceInput(3.3),
                ],
                //expected
                [5.9, -22, 12.8, 3.3],
                Lead::UPDATE_ACTION_FIXED,
            ],
        ];
    }

    /**
     * @dataProvider calculationsDataProvider
     *
     * @param array $offerRateParams
     * @param array $leadParams
     * @param array $updateLeadParams
     * @param array $expectedBalances
     *
     * @throws Throwable
     */
    public function testTransactionsCreatedCorrectlyWhenFixed(
        array $offerRateParams,
        array $leadParams,
        array $updateLeadParams,
        array $expectedBalances,
        string $leadUpdateAction
    ) {
        $offerRate = $this->getOfferRate($offerRateParams);
        $lead = $this->approveLead($offerRate, $leadParams);

        $this->updateLead($updateLeadParams, $lead);

        $event = new LeadUpdated($lead, $leadUpdateAction, $lead->getOriginal('merchant_payment'));
        $this->listener->handle($event);

        $lastOperation = $lead->operation()
            ->where('type', Operation::TYPE_LEAD_APPROVED)
            ->latest()
            ->firstOrFail();

        $rollbackOperation = $lead->operation()
            ->where('type', Operation::TYPE_LEAD_REJECTED)
            ->latest()
            ->firstOrFail();

        $this->checkOperation($lastOperation, $lead, Operation::TYPE_LEAD_APPROVED);
        $this->checkOperation($rollbackOperation, $lead, Operation::TYPE_LEAD_REJECTED);

        /** @var NetworkAccount $systemAccount */
        $systemAccount = NetworkAccount::query()
            ->where(NetworkAccount::MORPH_TYPE_FIELD, NetworkAccount::OWNER_SYSTEM)
            ->where('currency', $offerRate->offer->currency)
            ->firstOrFail();

        $this->checkAccount($expectedBalances[0], 4, 5, $systemAccount);

        // /** @var NetworkAccount $merchantAccount */
        $merchantAccount = $offerRate->offer->merchant->networkAccounts()->firstOrFail();
        $this->checkAccount($expectedBalances[1], 1, 2, $merchantAccount);

        /** @var NetworkAccount $affiliateAccount */
        $affiliateAccount = $lead->campaign->affiliate->networkAccounts()->firstOrFail();
        $this->checkAccount($expectedBalances[2], 2, 1, $affiliateAccount);

        /** @var NetworkAccount $networkAccount */
        $networkAccount = $offerRate->offer->network->ownAccounts()->firstOrFail();
        $this->checkAccount($expectedBalances[3], 2, 1, $networkAccount);

        $this->checkOperationOwners($merchantAccount, $rollbackOperation, $affiliateAccount);
        $this->checkOperationOwners($merchantAccount, $lastOperation, $affiliateAccount);
    }

    /**
     * @param array $offerRateParams
     *
     * @return OfferRate
     */
    protected function getOfferRate(array $offerRateParams): OfferRate
    {
        /** @var OfferRate $offerRate */
        $offerRate = factory(OfferRate::class)->create([
            'merchant_payment_type'     => $offerRateParams[0],
            'merchant_payment_amount'   => $offerRateParams[1],
            'commission_payment_amount' => $offerRateParams[2],
        ]);
        return $offerRate;
    }

    public function approveLead(OfferRate $offerRate, array $leadParams): Lead
    {
        /** @var Lead $lead */
        $lead = factory(Lead::class)->create(
            $leadParams + [
                'offer_rate_id' => $offerRate->id,
                'status'        => Lead::STATUS_APPROVED,
            ]
        );

        $event = new LeadApproved($lead, $offerRate->offer->merchant->owner, Lead::STATUS_HOLD);
        $listener = app(LeadApproveMoneyTransactionsListener::class);
        $listener->handle($event);

        return $lead;
    }

    /**
     * @param array $updateLeadParams
     * @param Lead  $lead
     *
     * @return void
     */
    protected function updateLead(array $updateLeadParams, Lead $lead): void
    {
        $lead->merchant_payment = $updateLeadParams['merchant_payment'];
        $lead->affiliate_profit = $updateLeadParams['affiliate_profit'];
        $lead->network_profit = $updateLeadParams['network_profit'];
        $lead->save();
    }

    /**
     * @param Operation|MorphOne $operation
     * @param Lead               $lead
     * @param string             $operationType
     *
     * @return void
     */
    protected function checkOperation(
        Operation|MorphOne $operation,
        Lead $lead,
        string $operationType
    ): void {
        $this->assertNotNull($operation);
        $this->assertEquals(Lead::RELATION_MORPH, $operation->based_on_type);
        $this->assertEquals($lead->id, $operation->based_on_id);
        $this->assertEquals($operationType, $operation->type);
        $this->assertEquals(3, $operation->transactions()->count());
    }

    /**
     * @param                $expectedBalances
     * @param NetworkAccount $networkAccount
     *
     * @return void
     */
    protected function checkAccount(
        $expectedBalances,
        $transactionsToAccountCount,
        $transactionsFromAccountCount,
        NetworkAccount $networkAccount
    ): void {
        $this->assertEquals($expectedBalances, formatBalanceOutput($networkAccount->balance));
        $this->assertEquals($transactionsToAccountCount, $networkAccount->toTransactions()->count());
        $this->assertEquals($transactionsFromAccountCount, $networkAccount->fromTransactions()->count());
    }

    /**
     * @param Model|\Illuminate\Database\Eloquent\Relations\MorphMany $merchantAccount
     * @param                                                         $operation
     * @param NetworkAccount                                          $affiliateAccount
     *
     * @return void
     */
    protected function checkOperationOwners(
        Model|\Illuminate\Database\Eloquent\Relations\MorphMany $merchantAccount,
        $operation,
        NetworkAccount $affiliateAccount
    ): void {
        $this->assertEquals($merchantAccount->id, $operation->account_from);
        $this->assertEquals($affiliateAccount->id, $operation->account_to);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->listener = app(LeadUpdateMoneyTransactionsListener::class);
    }
}
