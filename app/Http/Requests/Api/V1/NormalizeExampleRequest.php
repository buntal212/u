<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use JsonException;

#[FailOnUnknownFields]
class NormalizeExampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'value' => ['bail', 'required', 'string', 'max:1000', 'not_regex:/^[\p{Z}\s]*$/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.required' => 'Field value wajib diisi.',
            'value.string' => 'Field value harus berupa teks.',
            'value.max' => 'Field value maksimal :max karakter.',
            'value.not_regex' => 'Field value wajib diisi.',
        ];
    }

    /**
     * Validate raw JSON so TrimStrings cannot alter the original response
     * value or make an overlong value appear valid before validation.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        if (! $this->isJson()) {
            return parent::validationData();
        }

        try {
            $payload = json_decode($this->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }
}
