<?php

namespace Modules\Offers\Tracker\Leads;

use App\Components\Tracker\Resources\BaseModelResource;
use App\Dictionaries\Tracker\MethodsDict;
use Illuminate\Database\Eloquent\Model;
use Modules\Offers\Entities\Lead;
use Modules\Offers\Traits\Tracker\LeadTrackingParams;

/**
 * Class LeadApproveTrackerResource
 *
 * @mixin Lead
 *
 * @package Modules\Offers\Tracker\Leads
 */
class LeadUpdateFixedTrackerResource extends BaseModelResource
{
    use LeadTrackingParams;

    /**
     * @param Model $model
     * @param float $merchant_amount
     * @param bool  $force
     */
    public function __construct(
        Model $model,
        private float $merchant_amount,
        protected bool $force = true
    ) {
        parent::__construct($model);
    }

    public static function getMethod(): string
    {
        return MethodsDict::LEADS_UPDATE_FIXED;
    }

    public function getParams(): array
    {
        $params = [
            'id'              => $this->idParam(),
            'merchant_amount' => $this->merchant_amount,
            'force'           => $this->force,
        ];

        return $params;
    }
}
