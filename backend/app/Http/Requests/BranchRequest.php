<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('branches.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'branch_code' => [$required, 'string', 'max:30', 'alpha_dash', Rule::unique('branches', 'branch_code')->ignore($this->route('branch')?->id)],
            'branch_name' => [$required, 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['branch_code.unique' => 'Kode cabang sudah digunakan (termasuk cabang yang sudah dihapus).'];
    }
}
