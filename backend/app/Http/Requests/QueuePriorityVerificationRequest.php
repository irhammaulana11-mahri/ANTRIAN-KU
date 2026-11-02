<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QueuePriorityVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('queues.assign') === true;
    }

    public function rules(): array
    {
        return ['priority_type_id' => ['required', 'uuid', 'exists:priority_types,id'], 'reason' => ['nullable', 'string', 'max:500']];
    }
}
