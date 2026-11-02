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

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $counter = $this->route('counter');
        $branchId = $counter?->branch_id ?? $this->input('branch_id') ?? $this->user()?->branch_id;

        return [
            'branch_id' => ['nullable', 'uuid'],
            'counter_code' => [$required, 'string', 'max:40', 'alpha_dash', Rule::unique('counters', 'counter_code')->where('branch_id', $branchId)->ignore($counter?->id)],
            'counter_name' => [$required, 'string', 'max:150'],
            // Dipakai Central Display untuk memisahkan area Customer Service dan Teller.
            'counter_type' => [$required, 'string', Rule::in(['CS', 'TL'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['counter_code.unique' => 'Kode loket sudah digunakan pada cabang ini (termasuk loket yang sudah dihapus).'];
    }
}
