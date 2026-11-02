<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManualAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('queues.assign') === true;
    }

    public function rules(): array
    {
        return ['employee_id' => ['required', 'uuid', 'exists:employees,id'], 'counter_id' => ['required', 'uuid', 'exists:counters,id'], 'reason' => ['required', 'string', 'max:500']];
    }
}
