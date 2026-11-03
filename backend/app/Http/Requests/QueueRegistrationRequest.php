<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QueueRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'unit_id' => ['nullable', 'uuid', 'exists:units,id'],
            'service_type_id' => ['required', 'uuid', 'exists:service_types,id'],
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'service_notes' => ['nullable', 'string', 'max:2000'],
            'priority_type_id' => ['nullable', 'uuid', 'exists:priority_types,id'],
        ];
    }
}
