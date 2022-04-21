<?php

namespace Modules\Offers\Actions\Leads\Abstracts;

use App\Actions\TrackerTasks\TrackerTaskCreateAction;
use App\Entities\TrackerTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Offers\Entities\Pivots\TrackerTaskLeadPivot;
use Modules\Offers\Events\Leads\LeadTaskCreated;
use Modules\Users\Entities\User;

abstract class LeadTrackerTaskCreateAction
{
    public function __construct(
        protected TrackerTaskCreateAction $trackerTaskCreateAction
    ) {
    }

    protected function createTask(string $action, array $ids, array $params, User $user, bool $force): ?TrackerTask
    {
        if (! $filteredIds = $this->filterIds($ids)) {
            return null;
        }

        /** @var TrackerTask|null $task */
        $task = DB::transaction(function () use ($action, $filteredIds, $params, $user, $force) {
            $task = $this->trackerTaskCreateAction
                ->execute('lead', $action, $filteredIds, $params, $user, $force);

            $task->leads()->sync(array_fill_keys($task->ids, [
                'status'     => TrackerTask::STATUS_PENDING,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]));

            return $task;
        });

        LeadTaskCreated::dispatch($task);

        return $task;
    }

    protected function filterIds(array $requestIds): array
    {
        $ignoreIds = TrackerTaskLeadPivot::query()
            ->whereIn('lead_id', $requestIds)
            ->pluck('lead_id')
            ->toArray();

        $idsDiff = array_diff($requestIds, $ignoreIds);
        sort($idsDiff);
        return array_map(fn($id) => (int) $id, $idsDiff);
    }
}
