<?php

namespace Modules\Offers\Policies;

use Modules\Networks\Entities\NetworkParticipant;
use Modules\Offers\Entities\Lead;
use Modules\Users\Entities\User;

class LeadPolicy
{
    public function view(User $user, Lead $lead)
    {
        return $this->isLeadAffiliate($user, $lead)
               || $this->isLeadMerchant($user, $lead)
               || $this->isLeadNetworkSide($user, $lead);
    }

    public function history(User $user, Lead $lead)
    {
        return $this->isLeadAffiliate($user, $lead)
               || $this->isLeadMerchant($user, $lead)
               || $this->isLeadNetworkSide($user, $lead);
    }

    public function approve(User $user, Lead $lead)
    {
        return $this->isLeadMerchant($user, $lead) || $this->isLeadNetworkSide($user, $lead);
    }

    public function reject(User $user, Lead $lead)
    {
        return $this->isLeadMerchant($user, $lead) || $this->isLeadNetworkSide($user, $lead);
    }

    public function update(User $user, Lead $lead)
    {
        return $this->isLeadMerchant($user, $lead) || $this->isLeadNetworkSide($user, $lead);
    }

    /**
     * @param User  $user
     * @param array $leadIds
     *
     * @return bool
     */
    public function batch(User $user, array $leadIds)
    {
        $leads = Lead::query()
            ->with(['network', 'merchant', 'offer'])
            ->findMany($leadIds);

        $result = $leads->isNotEmpty() && ($leads->count() === count($leadIds));

        if (! $result) {
            return false;
        }

        foreach ($leads as $lead) {
            $allowSingle = $this->isLeadMerchant($user, $lead) || $this->isLeadNetworkSide($user, $lead);

            if (! $allowSingle) {
                $result = false;
                break;
            }
        }

        return $result;
    }

    protected function isLeadAffiliate(User $user, Lead $lead)
    {
        if (! $user->isAffiliate()) {
            return false;
        }

        return $lead->affiliate->owner_id === $user->id;
    }

    protected function isLeadMerchant(User $user, Lead $lead): bool
    {
        if (! $user->isMerchant()) {
            return false;
        }

        return $lead->merchant->owner_id === $user->id;
    }

    protected function isLeadNetworkSide(User $user, Lead $lead): bool
    {
        switch ($user->role) {
            case User::ROLE_NETWORK:
                return $lead->network->owner_id === $user->id;
            case User::ROLE_MANAGER_MERCHANT:
                return $this->isLeadOfferManager($user, $lead);
            case User::ROLE_MANAGER_AFFILIATE:
                return $this->isLeadCampaignOwnerManager($user, $lead);
            case User::ROLE_MANAGER_ADMIN:
                if (! $manager = $user->networkManager) {
                    return false;
                }
                return $lead->network->managers()->where('id', $manager->id)->exists();
            default:
                return false;
        }
    }

    protected function isLeadOfferManager(User $user, Lead $lead): bool
    {
        if (! $user->isMerchantManager()) {
            return false;
        }

        $manager = $user->networkManager;

        if (! $manager) {
            return false;
        }

        return $lead->offer->manager_id === $manager->id;
    }

    protected function isLeadCampaignOwnerManager(User $user, Lead $lead): bool
    {
        if (! $user->isAffiliateManager()) {
            return false;
        }

        $manager = $user->networkManager;

        if (! $manager) {
            return false;
        }

        return $lead->network->participants()
            ->where(NetworkParticipant::MORPH_TYPE_FIELD, NetworkParticipant::TYPE_AFFILIATE)
            ->where(NetworkParticipant::MORPH_ID_FIELD, $lead->affiliate_id)
            ->where('manager_id', $manager->id)
            ->exists();
    }
}
