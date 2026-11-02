<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QueueStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = [
            'ASSIGNED' => 'queues.assign',
            'CALLED' => 'queues.call',
            'SERVING' => 'queues.start',
            'COMPLETED' => 'queues.complete',
            'SKIPPED' => 'queues.skip',
            'NO_SHOW' => 'queues.skip',
            'CANCELLED' => 'queues.cancel',
            'WAITING' => 'queues.assign',
        ];

        return $this->user()?->can($permissions[$this->string('status')->toString()] ?? 'queues.assign') === true;
    }

    public function rules(): array
    {
        return ['status' => ['required', 'string', 'in:ASSIGNED,CALLED,SERVING,COMPLETED,SKIPPED,NO_SHOW,CANCELLED,WAITING'], 'reason' => ['nullable', 'string', 'max:500']];
    }
}
