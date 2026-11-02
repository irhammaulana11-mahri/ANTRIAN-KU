<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TokenCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['token' => ['required', 'string', 'min:32', 'max:200'], 'reason' => ['nullable', 'string', 'max:500']];
    }
}
