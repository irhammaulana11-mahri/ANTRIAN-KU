<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceActionRequest extends FormRequest
{
    public const PERMISSIONS = [
        'call' => 'queues.call',
        'recall' => 'queues.recall',
        'start' => 'queues.start',
        'complete' => 'queues.complete',
        'skip' => 'queues.skip',
        'no-show' => 'queues.skip',
    ];

    public function authorize(): bool
    {
        $permission = self::PERMISSIONS[$this->route('action')] ?? null;

        return $permission !== null && $this->user()?->can($permission) === true;
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
