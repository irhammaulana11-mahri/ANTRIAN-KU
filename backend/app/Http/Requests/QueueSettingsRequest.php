<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class QueueSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') === true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'uuid'],
            'online_daily_quota' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'max_waiting_threshold' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'operating_hours' => ['sometimes', 'array', 'size:7'],
            'operating_hours.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'operating_hours.*.is_closed' => ['required', 'boolean'],
            'operating_hours.*.opens_at' => ['required_if:operating_hours.*.is_closed,false', 'nullable', 'date_format:H:i'],
            'operating_hours.*.closes_at' => ['required_if:operating_hours.*.is_closed,false', 'nullable', 'date_format:H:i'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('operating_hours', []) as $index => $row) {
                if (empty($row['is_closed']) && ($row['opens_at'] ?? '') >= ($row['closes_at'] ?? '')) {
                    $validator->errors()->add("operating_hours.{$index}.closes_at", 'Jam tutup harus setelah jam buka.');
                }
            }
        });
    }
}
