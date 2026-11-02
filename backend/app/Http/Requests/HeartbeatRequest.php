<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.status') === true;
    }

    public function rules(): array
    {
        return [];
    }
}
