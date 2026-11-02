<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DistributionNextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('queues.assign') === true;
    }

    public function rules(): array
    {
        return ['branch_id' => ['nullable', 'uuid'], 'service_type_id' => ['required', 'uuid', 'exists:service_types,id']];
    }
}
