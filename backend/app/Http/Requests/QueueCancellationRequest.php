<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QueueCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['token' => ['required_without:reason', 'nullable', 'string', 'min:32', 'max:200'], 'reason' => ['nullable', 'string', 'max:500']];
    }
}
