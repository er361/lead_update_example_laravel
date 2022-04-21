<?php

namespace Modules\Offers\Subscribers;

use Illuminate\Events\Dispatcher;
use Modules\Offers\Entities\Lead;
use Modules\Offers\Entities\LeadActionLog;
use Modules\Offers\Events\Leads\LeadActions\LeadApproved;
use Modules\Offers\Events\Leads\LeadActions\LeadRejected;
use Modules\Offers\Events\Leads\LeadActions\LeadUpdated;

class LeadActionsLoggerSubscriber
{
    /**
     * Register the listeners for the subscriber.
     *
     * @param Dispatcher $events
     */
    public function subscribe($events)
    {
        $events->listen(
            LeadApproved::class,
            self::class . '@' . 'onLeadApproved'
        );

        $events->listen(
            LeadRejected::class,
            self::class . '@' . 'onLeadRejected'
        );

        $events->listen(
            LeadUpdated::class,
            self::class . '@' . 'onLeadUpdated'
        );
    }


    public function onLeadUpdated(LeadUpdated $event)
    {
        $this->writeLogEntry(
            $event->lead->id,
            $event->updateAction,
            $event->lead->merchant?->owner?->id,
            null,
            [
                'payment_before' => formatBalanceOutput($event->oldPayment),
                'payment_after'  => formatBalanceOutput($event->lead->merchant_payment),
            ]
        );
    }

    public function onLeadApproved(LeadApproved $event)
    {
        $this->writeLogEntry(
            $event->lead->id,
            LeadActionLog::EVENT_APPROVE,
            $event->actionCaller->id,
            null,
            [
                'status_before' => $event->oldStatus,
                'status_after'  => $event->lead->status,
            ],
        );

        if ($event->oldPayment) {
            $this->writeLogEntry(
                $event->lead->id,
                LeadActionLog::EVENT_COST_CHANGED,
                $event->actionCaller->id,
                $event->changeCostComment,
                [
                    'payment_before' => formatBalanceOutput($event->oldPayment),
                    'payment_after'  => formatBalanceOutput($event->lead->merchant_payment),
                ]
            );
        }
    }

    public function onLeadRejected(LeadRejected $event)
    {
        $data = [
            'status_before' => Lead::STATUS_HOLD,
            'status_after'  => $event->lead->status,
        ];

        $this->writeLogEntry(
            $event->lead->id,
            LeadActionLog::EVENT_REJECT,
            $event->actionCaller->id,
            $event->comment,
            $data
        );
    }

    protected function writeLogEntry(
        int $leadId,
        string $event,
        int $userId = null,
        ?string $comment = null,
        ?array $data = null
    ) {
        $actionLog = new LeadActionLog(compact('event', 'comment', 'data'));

        $actionLog->lead_id = $leadId;
        $actionLog->user_id = $userId;

        $actionLog->save();
    }
}
