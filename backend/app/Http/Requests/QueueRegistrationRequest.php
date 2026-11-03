<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Unit harus aktif dan milik cabang yang dipilih.
            'unit_id' => ['nullable', 'uuid', Rule::exists('units', 'id')->where('branch_id', $this->input('branch_id'))->where('is_active', true)->whereNull('deleted_at')],
            'service_type_id' => ['required', 'uuid', 'exists:service_types,id'],
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'service_notes' => ['nullable', 'string', 'max:2000'],
            'priority_type_id' => ['nullable', 'uuid', 'exists:priority_types,id'],
        ];
    }
}
