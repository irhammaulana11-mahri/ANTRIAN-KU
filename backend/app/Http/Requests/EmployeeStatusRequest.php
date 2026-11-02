<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.status') === true;
    }

    public function rules(): array
    {
        return ['status' => ['required', 'string', 'in:AVAILABLE,BUSY,BREAK,OFFLINE,CLOSED'], 'reason' => ['nullable', 'string', 'max:500']];
    }
}
