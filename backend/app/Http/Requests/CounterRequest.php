<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('counters.manage') === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('unit_id') === '') {
            $this->merge(['unit_id' => null]);
        }
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $counter = $this->route('counter');
        $user = $this->user();
        $branchId = $counter?->branch_id ?? $this->input('branch_id') ?? $user?->branch_id;

        return [
            'branch_id' => ['nullable', 'uuid'],
            // Admin Cabang wajib memilih unit; Admin Unit otomatis memakai unitnya (diabaikan di service).
            'unit_id'   => [
                Rule::requiredIf(fn () => $user?->unit_id === null && $user?->branch_id !== null && $this->isMethod('POST')),
                'nullable', 'uuid',
                Rule::exists('units', 'id')->where('branch_id', $branchId)->whereNull('deleted_at'),
            ],
            'counter_code' => [$required, 'string', 'max:40', 'alpha_dash', Rule::unique('counters', 'counter_code')->where('branch_id', $branchId)->ignore($counter?->id)],
            'counter_name' => [$required, 'string', 'max:150'],
            // Dipakai Central Display untuk memisahkan area Customer Service dan Teller.
            'counter_type' => [$required, 'string', Rule::in(['CS', 'TL'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'counter_code.unique' => 'Kode loket sudah digunakan pada cabang ini (termasuk loket yang sudah dihapus).',
            'unit_id.required' => 'Pilih unit.',
            'unit_id.exists' => 'Unit tidak ditemukan pada cabang ini.',
        ];
    }
}
