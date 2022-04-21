<?php

namespace Modules\Offers\Components\Leads;

use App\Dictionaries\Tracker\MethodsDict;
use App\Exceptions\DatabaseErrorException;
use Exception;
use Modules\Offers\Entities\Lead;
use Modules\Offers\Entities\OfferRate;
use Modules\Offers\Events\Leads\LeadActions\LeadApproved;
use Modules\Offers\Events\Leads\LeadActions\LeadRejected;
use Modules\Offers\Events\Leads\LeadActions\LeadUpdated;
use Modules\Offers\Exceptions\BadActionCallerException;
use Modules\Offers\Exceptions\Leads\LeadActionsService\ActionNotFound;
use Modules\Offers\Exceptions\Leads\LeadActionsService\BadLeadStatusException;
use Modules\Offers\Exceptions\Leads\LeadActionsService\LeadPaymentTypeIsNotPercentException;
use Modules\Users\Entities\User;

class LeadActionsService
{
    /**
     * @param Lead        $lead
     * @param User        $actionCaller
     * @param float|null  $newPayment
     * @param string|null $changeCostComment
     *
     * @throws BadLeadStatusException
     * @throws DatabaseErrorException
     * @throws LeadPaymentTypeIsNotPercentException
     * @throws Exception
     */
    public function approve(
        Lead $lead,
        User $actionCaller,
        float $newPayment = null,
        string $changeCostComment = null,
    ) {
        // $this->checkLeadOwnership($actionCaller, $lead);

        if ($lead->isApproved()) {
            throw new BadLeadStatusException();
        }

        $oldPayment = null;
        $oldStatus = $lead->status;

        if (isset($newPayment)) {
            $offerRate = $lead->offerRate;
            if (! $offerRate || $offerRate->merchant_payment_type !== OfferRate::PAYMENT_TYPE_PERCENT) {
                throw new LeadPaymentTypeIsNotPercentException();
            }
            $oldPayment = $lead->merchant_payment;

            $leadRewards = $offerRate->calculateLeadRewards($newPayment, $lead->affiliate->id);
            $lead->merchant_payment = $leadRewards['merchant_payment'];
            $lead->affiliate_profit = $leadRewards['affiliate_profit'];
            $lead->network_profit = $leadRewards['network_profit'];
        } else {
            $changeCostComment = null;
        }

        $lead->prev_status = $oldStatus;
        $lead->status = Lead::STATUS_APPROVED;
        $lead->terminated_at = now();

        if (! $lead->save()) {
            throw new DatabaseErrorException();
        }

        event(
            new LeadApproved(
                $lead,
                $actionCaller,
                $oldStatus,
                $oldPayment,
                $changeCostComment,
            )
        );
    }

    public function update(Lead $lead, float $newPayment, float $oldPayment)
    {
        $action = $lead->trackingPivot?->task?->action;

        if (! $action) {
            $message = sprintf(
                'Lead update action not found,possible values %s',
                implode(',', MethodsDict::LEADS_UPDATE_GROUP)
            );
            throw new ActionNotFound($message);
        }

        if ($lead->isRejected()) {
            throw new BadLeadStatusException(
                sprintf('Cannot update lead with reject status, leadId = %s', $lead->id)
            );
        }

        if (isset($newPayment)) {
            $offerRate = $lead->offerRate;
            if (
                ! $offerRate
                || $offerRate->merchant_payment_type !== OfferRate::PAYMENT_TYPE_PERCENT
            ) {
                throw new LeadPaymentTypeIsNotPercentException();
            }

            $leadRewards = $offerRate->calculateLeadRewards($newPayment, $lead->affiliate->id);
            $lead->merchant_payment = $leadRewards['merchant_payment'];
            $lead->affiliate_profit = $leadRewards['affiliate_profit'];
            $lead->network_profit = $leadRewards['network_profit'];
        }

        if (! $lead->save()) {
            throw new DatabaseErrorException();
        }

        event(
            new LeadUpdated(
                $lead,
                $action,
                $oldPayment
            )
        );
    }

    /**
     * @param Lead   $lead
     * @param User   $actionCaller
     * @param string $comment
     * @param bool   $notifyTracker
     *
     * @throws BadLeadStatusException
     * @throws DatabaseErrorException
     */
    public function reject(Lead $lead, User $actionCaller, string $comment)
    {
        // $this->checkLeadOwnership($actionCaller, $lead);

        if ($lead->isRejected()) {
            throw new BadLeadStatusException();
        }

        $lead->prev_status = $lead->status;
        $lead->status = Lead::STATUS_REJECTED;
        $lead->reject_comment = $comment;
        $lead->terminated_at = now();

        if (! $lead->save()) {
            throw new DatabaseErrorException();
        }

        event(new LeadRejected($lead, $actionCaller, $comment));
    }

    /**
     * @param User $owner
     * @param Lead $lead
     *
     * @throws BadActionCallerException
     */
    protected function checkLeadOwnership(User $owner, Lead $lead)
    {
        switch ($owner->role) {
            case User::ROLE_MERCHANT:
                $merchant = $owner->merchant;

                if (! $merchant) {
                    throw new BadActionCallerException();
                }

                if ($merchant->id !== $lead->campaign->offer->merchant_id) {
                    throw new BadActionCallerException();
                }
                break;

            case User::ROLE_MANAGER_MERCHANT:
                $manager = $owner->networkManager;

                if (! $manager) {
                    throw new BadActionCallerException();
                }

                if ($manager->id !== $lead->campaign->offer->manager_id) {
                    throw new BadActionCallerException();
                }
                break;

            case User::ROLE_NETWORK:
                if ($lead->campaign->offer->network->owner_id !== $owner->id) {
                    throw new BadActionCallerException();
                }
                break;

            case User::ROLE_MANAGER_ADMIN:
                $manager = $owner->networkManager;

                if (! $manager) {
                    throw new BadActionCallerException();
                }

                if (! $lead->campaign->offer->network->managers()->where('id', $manager->id)->exists()) {
                    throw new BadActionCallerException();
                }
                break;

            default:
                throw new BadActionCallerException();
        }
    }
}
