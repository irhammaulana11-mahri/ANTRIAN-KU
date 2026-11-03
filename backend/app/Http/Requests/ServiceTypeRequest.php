<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.manage') === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('unit_id') === '') {
            $this->merge(['unit_id' => null]);
        }
        if (is_string($this->input('queue_prefix'))) {
            $this->merge(['queue_prefix' => strtoupper(trim($this->input('queue_prefix')))]);
        }
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $service = $this->route('service_type');
        $branchId = $service?->branch_id ?? $this->input('branch_id') ?? $this->user()?->branch_id;
        $unique = fn (string $column) => Rule::unique('service_types', $column)->where('branch_id', $branchId)->ignore($service?->id);

        return [
            'branch_id' => ['nullable', 'uuid'],
            // Kosong = layanan berlaku untuk semua unit di cabang.
            'unit_id' => ['nullable', 'uuid', Rule::exists('units', 'id')->where('branch_id', $branchId)->whereNull('deleted_at')],
            'service_name' => [$required, 'string', 'max:150'],
            'queue_prefix' => [$required, 'string', 'max:10', 'regex:/^[A-Z0-9]+$/', $unique('queue_prefix')],
            'priority_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'unit_id.exists' => 'Unit tidak ditemukan pada cabang ini.',
            'queue_prefix.unique' => 'Prefix antrian sudah digunakan layanan lain pada cabang ini.',
            'queue_prefix.regex' => 'Prefix hanya boleh huruf kapital dan angka tanpa spasi atau tanda hubung.',
        ];
    }
}
