<?php

namespace Modules\Offers\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Notifications\Listeners\CampaignActivationListener;
use Modules\Notifications\Listeners\CampaignApproveListener;
use Modules\Notifications\Listeners\CampaignRejectListener;
use Modules\Notifications\Listeners\OfferActivatedListener;
use Modules\Notifications\Listeners\OfferApproveListener;
use Modules\Notifications\Listeners\OfferDisabledListener;
use Modules\Notifications\Listeners\OfferPausedListener;
use Modules\Notifications\Listeners\OfferPrivateListener;
use Modules\Notifications\Listeners\OfferRejectedListener;
use Modules\Notifications\Listeners\OfferSendToApproveListener;
use Modules\Offers\Events\Campaigns\CampaignActions\CampaignActivation;
use Modules\Offers\Events\Campaigns\CampaignActions\CampaignApproved;
use Modules\Offers\Events\Campaigns\CampaignActions\CampaignRejected;
use Modules\Offers\Events\Leads\LeadActions\LeadApproved;
use Modules\Offers\Events\Leads\LeadActions\LeadRejected;
use Modules\Offers\Events\Leads\LeadActions\LeadUpdated;
use Modules\Offers\Events\Offers\OfferActions\OfferActivated;
use Modules\Offers\Events\Offers\OfferActions\OfferApproved;
use Modules\Offers\Events\Offers\OfferActions\OfferApproveRejected;
use Modules\Offers\Events\Offers\OfferActions\OfferDisabled;
use Modules\Offers\Events\Offers\OfferActions\OfferPausedEvent;
use Modules\Offers\Events\Offers\OfferActions\OfferSentForApproval;
use Modules\Offers\Events\Offers\OfferPrivateAvailableEvent;
use Modules\Offers\Events\Offers\OfferPrivateDisableEvent;
use Modules\Offers\Listeners\LeadApproveMoneyTransactionsListener;
use Modules\Offers\Listeners\LeadRejectedMoneyTransactionsListener;
use Modules\Offers\Listeners\LeadUpdateMoneyTransactionsListener;
use Modules\Offers\Subscribers\CampaignActionsLoggerSubscriber;
use Modules\Offers\Subscribers\CampaignTrackingSubscriber;
use Modules\Offers\Subscribers\Events\OfferEventTrackingSubscriber;
use Modules\Offers\Subscribers\LeadActionsLoggerSubscriber;
use Modules\Offers\Subscribers\LeadTrackingSubscriber;
use Modules\Offers\Subscribers\OfferActionsLoggerSubscriber;
use Modules\Offers\Subscribers\OfferCapTrackingSubscriber;
use Modules\Offers\Subscribers\OfferRateTrackingSubscriber;
use Modules\Offers\Subscribers\OfferTrackingSubscriber;
use Modules\Offers\Subscribers\PromoToolTrackingSubscriber;

class EventServiceProvider extends ServiceProvider
{
    protected $subscribe = [
        CampaignActionsLoggerSubscriber::class,
        OfferActionsLoggerSubscriber::class,
        LeadActionsLoggerSubscriber::class,

        // Tracking subscribers
        CampaignTrackingSubscriber::class,
        LeadTrackingSubscriber::class,
        OfferRateTrackingSubscriber::class,
        OfferTrackingSubscriber::class,
        PromoToolTrackingSubscriber::class,
        OfferCapTrackingSubscriber::class,
        OfferEventTrackingSubscriber::class,
    ];

    protected $listen = [
        LeadApproved::class             => [
            LeadApproveMoneyTransactionsListener::class,
        ],
        LeadRejected::class             => [
            LeadRejectedMoneyTransactionsListener::class,
        ],
        LeadUpdated::class              => [
            LeadUpdateMoneyTransactionsListener::class,
        ],
        OfferPausedEvent::class         => [
            OfferPausedListener::class,
        ],
        OfferActivated::class           => [
            OfferActivatedListener::class,
        ],
        OfferDisabled::class            => [
            OfferDisabledListener::class,
        ],
        OfferSentForApproval::class     => [
            OfferSendToApproveListener::class,
        ],
        OfferApproveRejected::class     => [
            OfferRejectedListener::class,
        ],
        OfferApproved::class            => [
            OfferApproveListener::class,
        ],
        OfferPrivateDisableEvent::class => [
            OfferPrivateListener::class,
        ],
        OfferPrivateAvailableEvent::class => [
            OfferPrivateListener::class,
        ],
        CampaignApproved::class           => [
            CampaignApproveListener::class,
        ],
        CampaignRejected::class           => [
            CampaignRejectListener::class,
        ],
        CampaignActivation::class         => [
            CampaignActivationListener::class,
        ],
    ];

    public function boot()
    {
        parent::boot();
    }
}
