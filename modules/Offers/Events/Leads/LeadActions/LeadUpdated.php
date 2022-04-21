<?php

namespace Modules\Offers\Events\Leads\LeadActions;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Offers\Entities\Lead;

class LeadUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * @param Lead   $lead
     * @param string $updateAction
     * @param float  $oldPayment
     */
    public function __construct(
        public Lead $lead,
        public string $updateAction,
        public float $oldPayment,
    ) {
    }
}
