<?php

namespace Modules\Offers\Api\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Offers\Entities\Lead;

class UpdateLeadRequest extends FormRequest
{
    public function authorize()
    {
        /** @var Lead $lead */
        $lead = $this->route('lead');

        return Gate::allows('update', $lead);
    }

    public function rules()
    {
        return [
            'update_type'     => 'required|in:fixed,percent,retariffication',
            'price'           => 'required_if:update_type,percent|numeric|min:0.01',
            'merchant_amount' => 'required_if:update_type,fixed|numeric|min:0.01',
        ];
    }

    public function getParams(): array
    {
        return $this->only(['price', 'merchant_amount']);
    }
}
