<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pendaftaran dari kiosk. Cabang selalu dari perangkat (bukan dari input), dan kategori prioritas
 * tidak dapat diklaim sendiri oleh customer — prioritas hanya diverifikasi petugas.
 */
class KioskQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // otorisasi oleh middleware device.type:KIOSK
    }

    public function rules(): array
    {
        return [
            'service_type_id' => ['required', 'uuid'],
            'customer_name' => ['required', 'string', 'min:2', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'service_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
