<?php

namespace Modules\Offers\Listeners;

use App\Exceptions\DatabaseErrorException;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;
use Modules\Accounts\Entities\Operation;
use Modules\Accounts\Entities\Transaction;
use Modules\Offers\Entities\Lead;
use Modules\Offers\Events\Leads\LeadActions\LeadUpdated;
use Modules\Offers\Exceptions\Leads\LeadUpdateMoneyException;
use Modules\Offers\Listeners\Abstracts\LeadMoneyTransactionsListenerAbstract;
use Throwable;

class LeadUpdateMoneyTransactionsListener extends LeadMoneyTransactionsListenerAbstract
{
    private Lead      $lead;
    private Operation $operation;

    /**
     * @param LeadUpdated $event
     *
     * @throws Throwable
     */
    public function handle(LeadUpdated $event)
    {
        $this->lead = $event->lead;

        $lastOperation = $this->lead->operation()
            ->latest()
            ->firstOrFail();

        if ($lastOperation->type == Operation::TYPE_LEAD_REJECTED) {
            throw new LeadUpdateMoneyException(
                sprintf('Wrong last operation type; leadId = %s', $this->lead->id)
            );
        }

        $lastTransactionLeadParams = $this->getLeadFromLastTransaction($lastOperation);

        DB::transaction(function () use ($lastOperation, $lastTransactionLeadParams) {
            //откатить последнюю транзакцию
            $this->makeTransactions(
                Operation::TYPE_LEAD_REJECTED,
                $lastTransactionLeadParams['merchant_payment'],
                $lastTransactionLeadParams['affiliate_profit'],
                $lastTransactionLeadParams['network_profit'],
                true
            );

            //вернуть транзакции с новой ценой лида
            $this->makeTransactions(
                Operation::TYPE_LEAD_APPROVED,
                $this->lead->merchant_payment,
                $this->lead->affiliate_profit,
                $this->lead->network_profit
            );
        });
    }

    /**
     * @param MorphOne|Operation $lastOperation
     *
     * @return array
     */
    protected function getLeadFromLastTransaction(MorphOne|Operation $lastOperation): array
    {
        $leadFromLastTransaction = [];

        $lastOperation->transactions->each(function (Transaction $transaction) use (&$leadFromLastTransaction) {
            if (
                $transaction->accountFrom?->isMerchantOwner()
                && $transaction->accountTo?->isSystemOwner()
            ) {
                $leadFromLastTransaction['merchant_payment'] = $transaction->sum;
            }

            if (
                $transaction->accountFrom?->isSystemOwner()
                && $transaction->accountTo?->isAffiliateOwner()
            ) {
                $leadFromLastTransaction['affiliate_profit'] = $transaction->sum;
            }

            if (
                $transaction->accountFrom?->isSystemOwner()
                && $transaction->accountTo?->isNetworkOwner()
            ) {
                $leadFromLastTransaction['network_profit'] = $transaction->sum;
            }
        });
        return $leadFromLastTransaction;
    }

    /**
     * @param string $operationType
     * @param int    $merchantPayment
     * @param int    $affiliateProfit
     * @param int    $networkProfit
     *
     * @return void
     * @throws DatabaseErrorException
     */
    private function makeTransactions(
        string $operationType,
        int $merchantPayment,
        int $affiliateProfit,
        int $networkProfit,
        bool $reverse = false
    ) {
        $systemAccount = $this->getSystemAccount();
        $merchantAccount = $this->getMerchantAccount();
        $affiliateAccount = $this->getAffiliateAccount();
        $networkAccount = $this->getNetworkAccount();

        $lead = $this->lead;

        $operation = $lead->operation()->create(['type' => $operationType]);
        /** @var Operation $operation */
        $this->operation = $operation;

        if (! $reverse) { // нормальный поток

            // Транзакция от рекламодателя на системный счет
            $this->transaction($merchantAccount, $systemAccount, $merchantPayment);

            // Транзакция с системного счета на счет веба
            $this->transaction($systemAccount, $affiliateAccount, $affiliateProfit);

            // Транзакция с системного счета на счет сети
            $this->transaction($systemAccount, $networkAccount, $networkProfit);
        } else { //обратный поток

            // Транзакция с системного счета на счет рекла
            $this->transaction($systemAccount, $merchantAccount, $merchantPayment);

            // Транзакция со счета веба на системный
            $this->transaction($affiliateAccount, $systemAccount, $affiliateProfit);

            // Транзакция со счета сети на системный
            $this->transaction($networkAccount, $systemAccount, $networkProfit);
        }


        $this->proceedOperation($merchantAccount, $affiliateAccount);
    }

    protected function getLead(): Lead
    {
        return $this->lead;
    }

    protected function getOperation(): Operation
    {
        return $this->operation;
    }
}
