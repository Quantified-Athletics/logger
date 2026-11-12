<?php

namespace App\Sync\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListPrismAthletePublicationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'after' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'after.integer' => 'after must be a publication id.',
            'after.min' => 'after must be a publication id.',
        ];
    }

    public function afterId(): int
    {
        return (int) $this->validated('after', 0);
    }
}
