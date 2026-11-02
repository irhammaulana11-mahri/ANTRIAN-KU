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

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $employee = $this->route('employee');
        $branchId = $employee?->branch_id ?? $this->input('branch_id') ?? $this->user()?->branch_id;
        // Admin Cabang hanya boleh dibuat/diubah oleh pengguna yang berhak mengelola akun (Super Admin).
        $roles = ['Customer Service', 'Teller', 'Supervisor'];
        if ($this->user()?->can('users.manage')) {
            $roles[] = 'Admin Cabang';
        }

        return [
            'branch_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'email' => [$creating ? 'required_without:user_id' : 'sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($employee?->user_id)],
            'password' => [$creating ? 'required_without:user_id' : 'nullable', 'string', 'min:12', 'max:100'],
            'role_name' => [$creating ? 'required_without:user_id' : 'sometimes', 'string', Rule::in($roles)],
            'employee_code' => [$required, 'string', 'max:40', 'alpha_dash', Rule::unique('employees', 'employee_code')->where('branch_id', $branchId)->ignore($employee?->id)],
            'employee_name' => [$required, 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_code.unique' => 'Kode petugas sudah digunakan pada cabang ini (termasuk petugas yang sudah dihapus).',
            'email.unique' => 'Email sudah terdaftar pada akun lain.',
            'password.min' => 'Password minimal 12 karakter.',
            'role_name.in' => 'Role tidak diizinkan untuk akun Anda.',
        ];
    }
}
