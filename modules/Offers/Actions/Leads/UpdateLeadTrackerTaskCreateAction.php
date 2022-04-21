<?php

namespace Modules\Offers\Actions\Leads;

use Modules\Offers\Actions\Leads\Abstracts\LeadTrackerTaskCreateAction;
use Modules\Users\Entities\User;

class UpdateLeadTrackerTaskCreateAction extends LeadTrackerTaskCreateAction
{
    /**
     * @param array  $ids
     * @param string $action
     * @param array  $params
     * @param User   $user
     * @param bool   $force
     *
     * @return \App\Entities\TrackerTask|null
     */
    public function execute(array $ids, string $action, array $params, User $user, bool $force)
    {
        return $this->createTask($action, $ids, $params, $user, $force);
    }
}
