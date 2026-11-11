<?php

namespace App\Sync\Requests;

use App\Sync\Models\PrismProgramDestination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePrismProgramDestinationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $googleSheets = PrismProgramDestination::TYPE_GOOGLE_SHEETS;
        $momence = PrismProgramDestination::TYPE_MOMENCE;

        return [
            'upserts' => ['present', 'array'],
            'upserts.*.type' => ['required', 'string', Rule::in(PrismProgramDestination::TYPES)],
            'upserts.*.spreadsheet_id' => ["required_if:upserts.*.type,{$googleSheets}", 'string', 'max:255'],
            'upserts.*.sheet_id' => ["required_if:upserts.*.type,{$googleSheets}", 'integer', 'min:0'],
            'upserts.*.title' => ['nullable', 'string', 'max:255'],
            'upserts.*.momence_track' => ["required_if:upserts.*.type,{$momence}", 'nullable', 'string', Rule::in(PrismProgramDestination::MOMENCE_TRACKS)],
            'upserts.*.momence_cycle_prefix' => ["required_if:upserts.*.type,{$momence}", 'nullable', 'string', 'max:255'],
            'upserts.*.momence_bridge_prefix' => ["required_if:upserts.*.type,{$momence}", 'nullable', 'string', 'max:255'],
            'upserts.*.momence_bridge_weeks' => ["required_if:upserts.*.type,{$momence}", 'nullable', 'integer', 'min:1', 'max:65535'],
            'removals' => ['present', 'array'],
            'removals.*.type' => ['required', 'string', 'max:32'],
            'device_id' => ['nullable', 'string', 'max:36'],
        ];
    }
}
