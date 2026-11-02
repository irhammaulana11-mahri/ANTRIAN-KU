<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') === true;
    }

    public function rules(): array
    {
        return ['branch_id' => ['nullable', 'uuid'], 'holiday_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'holiday_name' => ['required', 'string', 'max:150']];
    }
}
