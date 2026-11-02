<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DisplayDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('displays.manage') === true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'uuid'],
            'device_code' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('display_devices', 'device_code')->where('branch_id', $this->input('branch_id') ?? $this->user()?->branch_id)],
            'device_name' => ['required', 'string', 'max:150'],
            'device_type' => ['sometimes', 'string', Rule::in(['DISPLAY', 'KIOSK'])],
        ];
    }
}
