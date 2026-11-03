<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $unit     = $this->route('unit');
        $branchId = $unit?->branch_id ?? $this->input('branch_id');

        return [
            'branch_id'  => ['sometimes', 'required', 'uuid', 'exists:branches,id'],
            'unit_code'  => ['required', 'string', 'max:40',
                Rule::unique('units', 'unit_code')
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->ignore($unit?->id),
            ],
            'unit_name'  => ['required', 'string', 'max:150'],
            'address'    => ['nullable', 'string', 'max:500'],
            'phone'      => ['nullable', 'string', 'max:40'],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
