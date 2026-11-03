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

    protected function prepareForValidation(): void
    {
        if ($this->input('unit_id') === '') {
            $this->merge(['unit_id' => null]);
        }
    }

    public function rules(): array
    {
        $user = $this->user();
        $branchId = $this->input('branch_id') ?? $user?->branch_id;

        return [
            'branch_id' => ['nullable', 'uuid'],
            // Wajib memilih unit bila cabang tidak membuka layanan langsung; kosong = perangkat cabang. Admin Unit otomatis unitnya.
            'unit_id' => [
                Rule::requiredIf(fn () => $user?->unit_id === null && ! app(\App\Services\BranchServiceMode::class)->servesDirectly($branchId)),
                'nullable', 'uuid',
                Rule::exists('units', 'id')->where('branch_id', $branchId)->whereNull('deleted_at'),
            ],
            'device_code' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('display_devices', 'device_code')->where('branch_id', $this->input('branch_id') ?? $this->user()?->branch_id)->whereNull('deleted_at')],
            'device_name' => ['required', 'string', 'max:150'],
            'device_type' => ['sometimes', 'string', Rule::in(['DISPLAY', 'KIOSK'])],
        ];
    }

    public function messages(): array
    {
        return ['unit_id.required' => 'Pilih unit.', 'unit_id.exists' => 'Unit tidak ditemukan pada cabang ini.'];
    }
}
