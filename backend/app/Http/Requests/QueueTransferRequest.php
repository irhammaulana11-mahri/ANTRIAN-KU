<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QueueTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('queues.transfer') === true;
    }

    public function rules(): array
    {
        return ['target_service_type_id' => ['required', 'uuid', 'exists:service_types,id'], 'reason' => ['required', 'string', 'max:500']];
    }
}
