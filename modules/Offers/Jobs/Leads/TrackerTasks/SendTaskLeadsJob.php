<?php

namespace Modules\Offers\Jobs\Leads\TrackerTasks;

use App\Components\Tracker\Abstracts\TrackerJob;
use App\Components\Tracker\Abstracts\TrackerTaskJob;
use App\Entities\TrackerTask;
use App\Jobs\Abstracts\SequenceJob;
use Illuminate\Support\Carbon;
use Modules\Offers\Entities\Lead;
use Modules\Offers\Entities\Pivots\TrackerTaskLeadPivot;
use Modules\Offers\Jobs\Leads\LeadApproveTrackerJob;
use Modules\Offers\Jobs\Leads\LeadRejectTrackerJob;
use Modules\Offers\Jobs\Leads\LeadUpdateTrackerJob;

class SendTaskLeadsJob extends TrackerTaskJob
{
    public function handle()
    {
        $task = $this->getTaskAttempted();

        $task->loadMissing([
            'leads.offerRate',
            'leads.affiliate',
        ]);

        $queue = $this->defineQueue($task->force);

        $remain = $task->leads->count();

        foreach ($task->leads as $lead) {
            $shouldWaiting = match ($task->action) {
                'approve' => $this->dispatchApproveJob($queue, $lead, $task->params),
                'reject' => $this->dispatchRejectJob($queue, $lead),
                'update.fixed',
                'update.percent',
                'update.retariffication' => $this->dispatchLeadUpdateJob($queue, $lead, $task->params, $task->action)
            };

            $pivotQuery = TrackerTaskLeadPivot::query()
                ->where('lead_id', $lead->trackingPivot->lead_id);

            if ($shouldWaiting) {
                $pivotQuery->update([
                    'status'     => TrackerTask::STATUS_WAITING,
                    'updated_at' => Carbon::now(),
                ]);
            } else {
                $pivotQuery->delete();
                $remain--;
            }
        }

        $task->status = $remain ? TrackerTask::STATUS_WAITING : TrackerTask::STATUS_DONE;
        $task->save();
    }

    /**
     * @param string $queue
     * @param Lead   $lead
     * @param array  $params
     * @param string $action
     *
     * @return void
     */
    protected function dispatchLeadUpdateJob(string $queue, Lead $lead, array $params, string $action): bool
    {
        $merchantAmount = $params['merchant_amount'] ?? null;
        $price = $params['price'] ?? null;

        LeadUpdateTrackerJob::dispatch($lead->id, $merchantAmount, $price, $action)->onQueue($queue);
        return true;
    }

    protected function dispatchApproveJob(string $queue, Lead $lead, array $params = []): bool
    {
        if ($lead->status === Lead::STATUS_APPROVED) {
            return false;
        }

        $newPayment = $params['cost'] ?? null;
        LeadApproveTrackerJob::dispatch($lead->id, $newPayment, true)->onQueue($queue);

        return true;
    }

    protected function dispatchRejectJob(string $queue, Lead $lead): bool
    {
        if ($lead->status === Lead::STATUS_REJECTED) {
            return false;
        }

        LeadRejectTrackerJob::dispatch($lead->id, true)->onQueue($queue);

        return true;
    }

    protected function defineQueue(bool $useFastQueue): string
    {
        return $useFastQueue ? TrackerJob::QUEUE : SequenceJob::QUEUE;
    }
}
