<?php

namespace Tests\Unit\Offers\Subscribers;

use Modules\Offers\Entities\Lead;
use Modules\Offers\Entities\LeadActionLog;
use Modules\Offers\Events\Leads\LeadActions\LeadApproved;
use Modules\Offers\Events\Leads\LeadActions\LeadRejected;
use Modules\Offers\Events\Leads\LeadActions\LeadUpdated;
use Modules\Offers\Subscribers\LeadActionsLoggerSubscriber;
use Tests\TestCase;

class LeadActionsLoggerSubscriberTest extends TestCase
{
    protected Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lead = factory(Lead::class)->make();
    }

    public function testLeadUpdateLogged()
    {
        $oldPayment = 12345;

        $this->lead->status = Lead::STATUS_APPROVED;
        $this->lead->save();

        $merchantOwner = $this->lead->merchant->owner;

        $event = new LeadUpdated($this->lead, Lead::UPDATE_ACTION_FIXED, $oldPayment);

        $leadActionsLoggerSubscriber = new LeadActionsLoggerSubscriber();
        $leadActionsLoggerSubscriber->onLeadUpdated($event);

        $this->lead->load('actionsLog');

        /** @var LeadActionLog $updateActionLog */
        $updateActionLog = $this->lead->actionsLog->first();

        $dataChangeCostExpect = [
            'payment_before' => formatBalanceOutput($oldPayment),
            'payment_after'  => formatBalanceOutput($this->lead->merchant_payment),
        ];

        $this->assertNotNull($updateActionLog);
        $this->assertEquals($this->lead->id, $updateActionLog->lead_id);
        $this->assertEquals($merchantOwner->id, $updateActionLog->user_id);
        $this->assertEquals(Lead::UPDATE_ACTION_FIXED, $updateActionLog->event);
        $this->assertEquals($dataChangeCostExpect, $updateActionLog->data);
    }

    public function testLeadApproveLogged()
    {
        $oldPayment = 12345;
        $commentChangeCostExpect = 'asd';

        $oldStatus = Lead::STATUS_HOLD;
        $this->lead->status = Lead::STATUS_APPROVED;
        $this->lead->save();

        $merchantOwner = $this->lead->merchant->owner;

        event(new LeadApproved($this->lead, $merchantOwner, $oldStatus, $oldPayment, $commentChangeCostExpect));

        $this->lead->load('actionsLog');

        /** @var LeadActionLog $approveActionLog */
        $approveActionLog = $this->lead->actionsLog->first();
        $approveDataExpect = [
            'status_before' => $oldStatus,
            'status_after'  => $this->lead->status,
        ];
        $this->assertNotNull($approveActionLog);
        $this->assertEquals($this->lead->id, $approveActionLog->lead_id);
        $this->assertEquals($merchantOwner->id, $approveActionLog->user_id);
        $this->assertEquals(LeadActionLog::EVENT_APPROVE, $approveActionLog->event);
        $this->assertEquals($approveDataExpect, $approveActionLog->data);
        $this->assertNull($approveActionLog->comment);


        /** @var LeadActionLog $changeCostActionLog */
        $changeCostActionLog = $this->lead->actionsLog->last();
        $dataChangeCostExpect = [
            'payment_before' => formatBalanceOutput($oldPayment),
            'payment_after'  => formatBalanceOutput($this->lead->merchant_payment),
        ];
        $this->assertNotNull($changeCostActionLog);
        $this->assertEquals($this->lead->id, $changeCostActionLog->lead_id);
        $this->assertEquals($merchantOwner->id, $changeCostActionLog->user_id);
        $this->assertEquals(LeadActionLog::EVENT_COST_CHANGED, $changeCostActionLog->event);
        $this->assertEquals($dataChangeCostExpect, $changeCostActionLog->data);
        $this->assertEquals($commentChangeCostExpect, $changeCostActionLog->comment);
    }

    public function testLeadRejectLogged()
    {
        $oldStatus = Lead::STATUS_HOLD;
        $comment = 'asd';

        $this->lead->status = Lead::STATUS_REJECTED;
        $this->lead->save();

        $merchantOwner = $this->lead->campaign->offer->merchant->owner;

        event(new LeadRejected($this->lead, $merchantOwner, $comment));

        $data = [
            'status_before' => $oldStatus,
            'status_after'  => $this->lead->status,
        ];

        /** @var LeadActionLog $actionLog */
        $actionLog = $this->lead->actionsLog()->first();

        $this->assertNotNull($actionLog);
        $this->assertEquals($this->lead->id, $actionLog->lead_id);
        $this->assertEquals($merchantOwner->id, $actionLog->user_id);
        $this->assertEquals(LeadActionLog::EVENT_REJECT, $actionLog->event);
        $this->assertEquals($data, $actionLog->data);
        $this->assertEquals($comment, $actionLog->comment);
    }
}
