<?php

namespace Modules\Offers\Tracker\Leads;

use App\Components\Tracker\Resources\BaseModelResource;
use App\Dictionaries\Tracker\MethodsDict;
use Modules\Offers\Entities\Lead;
use Modules\Offers\Traits\Tracker\LeadTrackingParams;

/**
 * Class LeadApproveTrackerResource
 *
 * @mixin Lead
 *
 * @package Modules\Offers\Tracker\Leads
 */
class LeadUpdateRetarifficationTrackerResource extends BaseModelResource
{
    use LeadTrackingParams;
    public static function getMethod(): string
    {
        return MethodsDict::LEADS_UPDATE_RETARIFFICATION;
    }

    public function getParams(): array
    {
        $params = [
            'id' => $this->idParam(),
        ];

        return $params;
    }
}
