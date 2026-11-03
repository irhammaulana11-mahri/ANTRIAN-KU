<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('unit_id') === '') {
            $this->merge(['unit_id' => null]);
        }
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $employee = $this->route('employee');
        $branchId = $employee?->branch_id ?? $this->input('branch_id') ?? $this->user()?->branch_id;
        // Peran yang bisa dibuat bergantung pada peran pembuat.
        // Super Admin: semua peran. Admin Cabang: termasuk Admin Unit. Admin Unit: CS/Teller saja.
        $user = $this->user();
        $roles = ['Customer Service', 'Teller'];
        if ($user?->can('users.manage') && $user->unit_id === null) {
            // Admin Cabang ke atas: boleh buat Admin Unit
            $roles[] = 'Admin Unit';
        }
        if ($user?->branch_id === null) {
            // Super Admin saja: boleh buat Admin Cabang
            $roles[] = 'Admin Cabang';
        }

        return [
            'branch_id' => ['nullable', 'uuid'],
            // Admin Unit wajib terikat unit; Admin Cabang wajib memilih unit untuk staf di bawahnya.
            'unit_id'   => [
                Rule::requiredIf(fn () => $user?->unit_id === null && (
                    $this->input('role_name') === 'Admin Unit'
                    || ($creating && $user?->branch_id !== null)
                )),
                'nullable', 'uuid',
                Rule::exists('units', 'id')->where('branch_id', $branchId)->whereNull('deleted_at'),
            ],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'email' => [$creating ? 'required_without:user_id' : 'sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($employee?->user_id)],
            'password' => [$creating ? 'required_without:user_id' : 'nullable', 'string', 'min:8', 'max:100'],
            'role_name' => [$creating ? 'required_without:user_id' : 'sometimes', 'string', Rule::in($roles)],
            'employee_code' => ['nullable', 'string', 'max:40', 'alpha_dash', Rule::unique('employees', 'employee_code')->where('branch_id', $branchId)->ignore($employee?->id)],
            'employee_name' => [$required, 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_code.unique' => 'Kode petugas sudah digunakan pada cabang ini (termasuk petugas yang sudah dihapus).',
            'email.unique' => 'Email sudah terdaftar pada akun lain.',
            'password.min' => 'Password minimal 8 karakter.',
            'role_name.in' => 'Role tidak diizinkan untuk akun Anda.',
            'unit_id.required' => 'Pilih unit.',
            'unit_id.exists' => 'Unit tidak ditemukan pada cabang ini.',
        ];
    }
}
