<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompetencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') === true;
    }

    public function rules(): array
    {
        return ['service_type_ids' => ['present', 'array'], 'service_type_ids.*' => ['required', 'uuid', 'distinct', 'exists:service_types,id']];
    }
}
