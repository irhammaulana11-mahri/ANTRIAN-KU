<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeCountersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') === true;
    }

    public function rules(): array
    {
        return ['counter_ids' => ['present', 'array'], 'counter_ids.*' => ['required', 'uuid', 'distinct', 'exists:counters,id']];
    }
}
