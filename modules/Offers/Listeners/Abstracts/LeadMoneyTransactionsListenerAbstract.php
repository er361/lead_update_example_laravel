<?php

namespace Modules\Offers\Listeners\Abstracts;

use App\Exceptions\DatabaseErrorException;
use Modules\Accounts\Entities\Operation;
use Modules\Accounts\Entities\Transaction;
use Modules\Networks\Entities\NetworkAccount;
use Modules\Offers\Entities\Lead;

abstract class LeadMoneyTransactionsListenerAbstract
{
    abstract protected function getOperation(): Operation;

    abstract protected function getLead(): Lead;

    protected function getCurrency(): string
    {
        return $this->getLead()->offerRate->offer->currency;
    }

    /**
     * @param NetworkAccount $from
     * @param NetworkAccount $to
     *
     * @throws DatabaseErrorException
     */
    protected function proceedOperation(NetworkAccount $from, NetworkAccount $to): void
    {
        $operation = $this->getOperation();

        $operation->status = Operation::STATUS_PROCESSED;
        $operation->accountFrom()->associate($from);
        $operation->accountTo()->associate($to);

        if (! $operation->save()) {
            throw new DatabaseErrorException();
        }
    }

    /**
     * @param NetworkAccount $from
     * @param NetworkAccount $to
     * @param int            $sum
     *
     * @throws DatabaseErrorException
     */
    protected function transaction(NetworkAccount $from, NetworkAccount $to, int $sum): void
    {
        /** @var Transaction $transaction */
        $transaction = $this->getOperation()->transactions()->make([
            'currency' => $this->getCurrency(),
            'sum'      => $sum,
        ]);

        $transaction->accountFrom()->associate($from);
        $transaction->accountTo()->associate($to);

        if (! $transaction->save()) {
            throw new DatabaseErrorException();
        }
    }

    protected function getSystemAccount(): NetworkAccount
    {
        /** @var NetworkAccount $systemAccount */
        $systemAccount = NetworkAccount::query()
            ->where(NetworkAccount::MORPH_TYPE_FIELD, NetworkAccount::OWNER_SYSTEM)
            ->where('currency', $this->getCurrency())
            ->first();

        if (! $systemAccount) {
            $systemAccount = NetworkAccount::createSystemAccount($this->getCurrency());
        }

        return $systemAccount;
    }

    protected function getMerchantAccount(): NetworkAccount
    {
        $offer = $this->getLead()->offerRate->offer;

        /** @var NetworkAccount $merchantAccount */
        $merchantAccount = $offer->merchant->networkAccounts()
            ->where('network_id', $offer->network_id)
            ->where('currency', $this->getCurrency())
            ->first();

        if (! $merchantAccount) {
            $merchantAccount = $offer->merchant->networkAccounts()->make([
                'currency' => $this->getCurrency(),
            ]);

            $merchantAccount->network()->associate($offer->network_id);
            $merchantAccount->save();
        }

        return $merchantAccount;
    }

    protected function getAffiliateAccount(): NetworkAccount
    {
        $campaign = $this->getLead()->campaign;

        /** @var NetworkAccount $affiliateAccount */
        $affiliateAccount = $campaign->affiliate->networkAccounts()
            ->where('network_id', $campaign->offer->network_id)
            ->where('currency', $this->getCurrency())
            ->first();

        if (! $affiliateAccount) {
            $affiliateAccount = $campaign->affiliate->networkAccounts()->make([
                'currency' => $this->getCurrency(),
            ]);

            $affiliateAccount->network()->associate($campaign->offer->network_id);
            $affiliateAccount->save();
        }

        return $affiliateAccount;
    }

    protected function getNetworkAccount(): NetworkAccount
    {
        $network = $this->getLead()->offerRate->offer->network;

        /** @var NetworkAccount $networkAccount */
        $networkAccount = $network->ownAccounts()
            ->where('currency', $this->getCurrency())
            ->first();

        if (! $networkAccount) {
            $networkAccount = $network->createOwnAccount($this->getCurrency());
        }

        return $networkAccount;
    }
}
