<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use JsonException;

#[FailOnUnknownFields]
class CatalogSearchRequest extends FormRequest
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
            'search_id' => ['bail', 'required', 'string', 'max:64', 'not_regex:/^[\p{Z}\s]*$/u'],
            'query' => ['bail', 'required', 'string', 'max:200', 'not_regex:/^[\p{Z}\s]*$/u'],
            'limit' => ['bail', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'search_id.required' => 'Field search_id wajib diisi.',
            'search_id.string' => 'Field search_id harus berupa teks.',
            'search_id.max' => 'Field search_id maksimal :max karakter.',
            'query.required' => 'Field query wajib diisi.',
            'query.string' => 'Field query harus berupa teks.',
            'query.max' => 'Field query maksimal :max karakter.',
            'limit.integer' => 'Field limit harus berupa angka.',
            'limit.min' => 'Field limit minimal :min.',
            'limit.max' => 'Field limit maksimal :max.',
        ];
    }

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
