<?php

namespace Tests\Unit\Offers\Components\Leads;

use App\Entities\TrackerTask;
use App\Exceptions\DatabaseErrorException;
use Exception;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Modules\Offers\Components\Leads\LeadActionsService;
use Modules\Offers\Components\Leads\LeadsService;
use Modules\Offers\Entities\Lead;
use Modules\Offers\Entities\OfferRate;
use Modules\Offers\Events\Leads\LeadActions\LeadApproved;
use Modules\Offers\Events\Leads\LeadActions\LeadRejected;
use Modules\Offers\Events\Leads\LeadActions\LeadUpdated;
use Modules\Offers\Exceptions\Leads\LeadActionsService\ActionNotFound;
use Modules\Offers\Exceptions\Leads\LeadActionsService\BadLeadStatusException;
use Modules\Offers\Exceptions\Leads\LeadActionsService\LeadPaymentTypeIsNotPercentException;
use Modules\Users\Factories\UserFactory;
use Tests\TestCase;

class LeadActionsServiceTest extends TestCase
{
    protected LeadActionsService $service;
    protected Lead               $lead;
    protected MockInterface      $leadServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leadServiceMock = Mockery::mock(LeadsService::class);
        $this->app->instance(LeadsService::class, $this->leadServiceMock);

        $this->service = app(LeadActionsService::class);
        $this->lead = factory(Lead::class)->make();
    }

    public function approveDataProvider()
    {
        return [
            [Lead::STATUS_HOLD],
            [Lead::STATUS_HOLD, 123.45, null],
            [Lead::STATUS_REJECTED, 123.45, null],
            [Lead::STATUS_REJECTED, 123.45, null],
        ];
    }

    /**
     * @dataProvider approveDataProvider
     *
     * @param string      $oldStatus
     * @param float|null  $newPayment
     * @param string|null $changeCostComment
     *
     * @throws BadLeadStatusException
     * @throws DatabaseErrorException
     * @throws Exception
     */
    public function testApprove(string $oldStatus, ?float $newPayment = null, ?string $changeCostComment = null)
    {
        Event::fake([LeadApproved::class]);

        $this->lead->status = $oldStatus;
        $this->lead->save();

        if (isset($newPayment)) {
            $this->lead->offerRate->merchant_payment_type = OfferRate::PAYMENT_TYPE_PERCENT;
            $this->lead->offerRate->merchant_payment_amount = rand(1, 100);
            $this->lead->offerRate->save();
        }

        $oldPayment = $this->lead->merchant_payment;
        $merchantOwner = $this->lead->campaign->offer->merchant->owner;

        $this->service->approve($this->lead, $merchantOwner, $newPayment, $changeCostComment);

        Event::assertDispatched(LeadApproved::class, function (LeadApproved $e) use (
            $merchantOwner,
            $oldStatus,
            $newPayment,
            $oldPayment,
            $changeCostComment,
        ) {
            $validChangeCost = true;
            if (isset($newPayment)) {
                $validChangeCost = $e->oldPayment === $oldPayment
                                   && $e->changeCostComment === $changeCostComment;
            }
            return $e->lead->id === $this->lead->id
                   && $e->actionCaller->id === $merchantOwner->id
                   && $e->oldStatus === $oldStatus
                   && $validChangeCost;
        });

        $this->assertEquals(Lead::STATUS_APPROVED, $this->lead->status);
        $this->assertEquals($oldStatus, $this->lead->prev_status);
    }

    public function updateDataProvider(): array
    {
        return [
            [12.1, true],
            [10, false],
        ];
    }

    /**
     * @dataProvider updateDataProvider
     *
     * @param float $merchantPayment
     * @param bool  $trackerTaskFound
     *
     * @return void
     * @throws ActionNotFound
     * @throws BadLeadStatusException
     * @throws DatabaseErrorException
     * @throws LeadPaymentTypeIsNotPercentException
     */
    public function testUpdate(float $merchantPayment, bool $trackerTaskFound)
    {
        Event::fake();
        /**
         * @var $trackerTask TrackerTask
         */
        if ($trackerTaskFound) {
            $trackerTask = factory(TrackerTask::class)->create([
                'status'  => TrackerTask::STATUS_WAITING,
                'action'  => Lead::UPDATE_ACTION_PERCENT,
                'type'    => 'lead',
                'user_id' => UserFactory::new()->create()->id,
            ]);

            $this->lead->merchant_payment = 120;
            $this->lead->affiliate_profit = 69;
            $this->lead->network_profit = 13;
            $this->lead->save();

            $this->lead->offerRate->merchant_payment_type = OfferRate::PAYMENT_TYPE_PERCENT;
            $this->lead->offerRate->merchant_payment_amount = 73;
            $this->lead->offerRate->save();

            $trackerTask->leads()->attach($this->lead->id, ['status' => $trackerTask->status]);

            $expectAction = $trackerTask->action;
            $expectLeadId = $this->lead->id;

            $this->service->update($this->lead, $merchantPayment, $this->lead->merchant_payment);

            Event::assertDispatched(
                LeadUpdated::class,
                fn(LeadUpdated $event) => $event->updateAction == $expectAction
                                          && $event->lead->id == $expectLeadId
                                          && $event->lead->merchant_payment == 883
            );
        } else {
            $this->expectException(ActionNotFound::class);
            $this->service->update($this->lead, $merchantPayment, $this->lead->merchant_payment);
            Event::assertNotDispatched(LeadUpdated::class);
        }
    }

    /**
     * @throws BadLeadStatusException
     * @throws DatabaseErrorException
     * @throws LeadPaymentTypeIsNotPercentException
     */
    public function testApproveThrowsOnBadStatus()
    {
        $this->lead->status = Lead::STATUS_APPROVED;
        $this->lead->save();

        $merchantOwner = $this->lead->campaign->offer->merchant->owner;
        $this->expectException(BadLeadStatusException::class);

        $this->service->approve($this->lead, $merchantOwner);
    }

    public function rejectDataProvider(): array
    {
        return [
            [Lead::STATUS_HOLD, 'asd'],
            [Lead::STATUS_APPROVED, 'dsa'],
        ];
    }

    /**
     * @dataProvider rejectDataProvider
     *
     * @param string $oldStatus
     * @param string $comment
     *
     * @throws BadLeadStatusException
     * @throws DatabaseErrorException
     */
    public function testReject(string $oldStatus, string $comment)
    {
        Event::fake([LeadRejected::class]);

        $this->lead->status = $oldStatus;
        $this->lead->save();

        $merchantOwner = $this->lead->campaign->offer->merchant->owner;

        $this->service->reject($this->lead, $merchantOwner, $comment);

        Event::assertDispatched(LeadRejected::class, function (LeadRejected $e) use ($merchantOwner, $comment) {
            return $e->lead->id === $this->lead->id
                   && $e->actionCaller->id === $merchantOwner->id
                   && $e->comment === $comment;
        });

        $this->assertEquals(Lead::STATUS_REJECTED, $this->lead->status);
        $this->assertEquals($oldStatus, $this->lead->prev_status);
    }

    /**
     * @throws BadLeadStatusException
     * @throws DatabaseErrorException
     */
    public function testRejectThrowsOnBadStatus()
    {
        $comment = 'asd';

        $this->lead->status = Lead::STATUS_REJECTED;
        $this->lead->save();

        $merchantOwner = $this->lead->campaign->offer->merchant->owner;
        $this->expectException(BadLeadStatusException::class);

        $this->service->reject($this->lead, $merchantOwner, $comment);
    }

    /**
     * @throws BadLeadStatusException
     * @throws DatabaseErrorException
     * @throws Exception
     */
    public function testApproveThrowsOnFixedOfferRatePaymentType()
    {
        $comment = 'asd';
        $payment = 123.45;

        $this->lead->status = Lead::STATUS_HOLD;
        $this->lead->save();

        $merchantOwner = $this->lead->campaign->offer->merchant->owner;
        $this->expectException(LeadPaymentTypeIsNotPercentException::class);

        $this->service->approve($this->lead, $merchantOwner, $payment, $comment);
    }
}
