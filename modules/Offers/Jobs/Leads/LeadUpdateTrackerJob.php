<?php

namespace Modules\Offers\Jobs\Leads;

use App\Components\Tracker\Tracker;
use App\Jobs\Abstracts\SequenceJob;
use Illuminate\Support\Carbon;
use Modules\Offers\Entities\Lead;
use Modules\Offers\Entities\Pivots\TrackerTaskLeadPivot;
use Modules\Offers\Tracker\Leads\LeadUpdateFixedTrackerResource;
use Modules\Offers\Tracker\Leads\LeadUpdatePercentTrackerResource;
use Modules\Offers\Tracker\Leads\LeadUpdateRetarifficationTrackerResource;
use Throwable;

class LeadUpdateTrackerJob extends SequenceJob
{
    /**
     * @param int        $leadId
     * @param float|null $merchant_amount
     * @param float|null $price
     * @param string     $method
     * @param bool       $force
     */
    public function __construct(
        public int $leadId,
        public ?float $merchant_amount,
        public ?float $price,
        public string $method,
        public bool $force = true
    ) {
    }

    public function handle(Tracker $tracker)
    {
        if (! app()->environment('testing')) {
            sleep(1);
        }

        $lead = Lead::findOrFail($this->leadId);

        $resource = match ($this->method) {
            'update.fixed' => new LeadUpdateFixedTrackerResource($lead, $this->merchant_amount, $this->force),
            'update.percent' => new LeadUpdatePercentTrackerResource($lead, $this->price, $this->force),
            'update.retariffication' => new LeadUpdateRetarifficationTrackerResource($lead)
        };

        $resource->post($tracker);
    }

    public function failed(Throwable $e)
    {
        $pivotQuery = TrackerTaskLeadPivot::where('lead_id', $this->leadId);

        if ($pivotQuery->exists()) {
            $pivotQuery->update([
                'notice'     => $e->getMessage(),
                'updated_at' => Carbon::now(),
            ]);
        }

        Tracker::logger()->error(static::class . ': failed job');
    }

    public function tags(): array
    {
        return [...parent::tags(), $this->method, 'lead:' . $this->leadId];
    }
}
