<?php

namespace App\Http\Requests;

use App\Support\DisplayMedia;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DisplaySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') === true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'uuid'],
            'display_announcement' => ['nullable', 'string', 'max:500'],
            'voice_rate' => ['sometimes', 'numeric', 'between:0.5,2'],
            'voice_volume' => ['sometimes', 'numeric', 'between:0,1'],
            'media_volume' => ['sometimes', 'numeric', 'between:0,1'],
            'display_media' => ['sometimes', 'nullable', 'array', 'max:'.DisplayMedia::MAX_SOURCES],
            'display_media.*.type' => ['required', Rule::in(['STREAM', 'YOUTUBE'])],
            'display_media.*.url' => ['required', 'string', 'max:2000', 'url:http,https'],
            'display_media.*.label' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return ['display_media.max' => 'Maksimal '.DisplayMedia::MAX_SOURCES.' sumber video.', 'display_media.*.url.url' => 'Alamat harus berupa URL http:// atau https:// yang valid.'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('display_media', []) as $index => $source) {
                $url = (string) ($source['url'] ?? '');
                if ($url === '' || $validator->errors()->has("display_media.{$index}.url")) {
                    continue;
                }
                if (($source['type'] ?? null) === 'YOUTUBE' && DisplayMedia::parseYouTube($url) === null) {
                    $validator->errors()->add("display_media.{$index}.url", DisplayMedia::isNamedChannelLink($url)
                        ? 'Link kanal berbentuk @nama tidak dapat diputar langsung. Gunakan salah satu: (1) link sebuah video, (2) link playlist, atau (3) link kanal berformat https://www.youtube.com/channel/UC… (memutar semua unggahan kanal).'
                        : 'Link YouTube tidak dikenali. Gunakan link video, siaran langsung, atau playlist (contoh: https://www.youtube.com/watch?v=… atau https://youtu.be/…).');
                }
                if (($source['type'] ?? null) === 'STREAM') {
                    if (DisplayMedia::isYouTubeHost($url)) {
                        $validator->errors()->add("display_media.{$index}.url", 'Untuk link YouTube pilih tipe "YouTube".');
                    } elseif (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
                        $validator->errors()->add("display_media.{$index}.url", 'URL stream tidak boleh memuat username/password.');
                    }
                }
            }
        });
    }
}
