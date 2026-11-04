<?php

namespace App\Sync\Requests;

use App\Sync\Models\PrismProgramComment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StorePrismCommentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['upserts', 'removals'] as $key) {
            if ($this->input($key) === null) {
                $this->merge([$key => []]);
            }
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $max = PrismProgramComment::MAX_BODY_LENGTH;

        return [
            'upserts' => ['present', 'array'],
            'upserts.*.uuid' => ['required', 'string', 'uuid', 'distinct'],
            'upserts.*.workout_uuid' => ['required', 'string', 'uuid'],
            'upserts.*.body' => ['required', 'string', "max:{$max}"],
            'removals' => ['present', 'array'],
            'removals.*.uuid' => ['required', 'string', 'uuid', 'distinct'],
            'device_id' => ['nullable', 'string', 'max:36'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (empty($this->input('upserts', [])) && empty($this->input('removals', []))) {
                $validator->errors()->add('upserts', 'At least one upsert or removal is required.');
            }
        });
    }
}
