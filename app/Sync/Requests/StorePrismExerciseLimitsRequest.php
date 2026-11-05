<?php

namespace App\Sync\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePrismExerciseLimitsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'families' => ['present', 'array'],
            'families.upserts' => ['present', 'array'],
            'families.upserts.*.family_id' => ['required', 'string', 'max:255'],
            'families.upserts.*.name' => ['required', 'string', 'max:255'],
            ...$this->capRules('families.upserts.*'),
            'families.removals' => ['present', 'array'],
            'families.removals.*.family_id' => ['required', 'string', 'max:255'],
            'members' => ['present', 'array'],
            'members.upserts' => ['present', 'array'],
            'members.upserts.*.family_id' => ['required', 'string', 'max:255'],
            'members.upserts.*.exercise_id' => ['required', 'string', 'max:255'],
            'members.upserts.*.is_member' => ['required', 'boolean:strict'],
            'members.removals' => ['present', 'array'],
            'members.removals.*.family_id' => ['required', 'string', 'max:255'],
            'members.removals.*.exercise_id' => ['required', 'string', 'max:255'],
            'caps' => ['present', 'array'],
            'caps.upserts' => ['present', 'array'],
            'caps.upserts.*.exercise_id' => ['required', 'string', 'max:255'],
            ...$this->capRules('caps.upserts.*'),
            'caps.removals' => ['present', 'array'],
            'caps.removals.*.exercise_id' => ['required', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:36'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function capRules(string $prefix): array
    {
        return [
            "{$prefix}.cap_count" => ['present', 'nullable', 'integer:strict', 'min:0', 'max:4294967295', "required_with:{$prefix}.cap_period"],
            "{$prefix}.cap_period" => ['present', 'nullable', 'in:week,program', "required_with:{$prefix}.cap_count"],
        ];
    }
}
