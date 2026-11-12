<?php

namespace App\Sync\Requests;

use App\Sync\Models\PrismAthletePublication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use stdClass;

class StorePrismAthletePublicationRequest extends FormRequest
{
    /** Only an admin may publish to Athlete. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole('Admin');
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'track' => ['required', 'string', Rule::in(PrismAthletePublication::TRACKS)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'raw' => ['required', 'array'],
            'raw.metadata' => ['present', 'array'],
            'raw.workouts' => ['required', 'array', 'list', 'min:1'],
            'raw.workouts.*' => ['required', 'array'],
            'raw.workouts.*.id' => ['required', 'string', 'regex:/^w[0-9]+d[0-9]+$/'],
            'raw.workouts.*.week' => ['required', 'integer', 'min:1'],
            'raw.workouts.*.day' => ['required', 'integer', 'min:1'],
            'raw.workouts.*.text' => ['present', 'array', 'list'],
            'raw.workouts.*.text.*' => ['string'],
            'raw.workouts.*._formatting' => ['present', 'array'],
            'raw.workouts.*._formatting.formattedText' => ['present', 'array', 'list'],
            'raw.workouts.*._formatting.formattedText.*' => ['string'],
            'device_id' => ['nullable', 'string', 'max:36'],
        ];
    }

    /**
     * Checks the decoded-array rules cannot make: that `raw` and `raw.metadata` are JSON objects
     * (not lists), and that the raw file fits the size cap.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $raw = $this->rawFile();

                if (! $raw instanceof stdClass) {
                    $validator->errors()->add('raw', 'The raw file must be a JSON object.');

                    return;
                }

                if (! isset($raw->metadata) || ! $raw->metadata instanceof stdClass) {
                    $validator->errors()->add('raw.metadata', 'The raw metadata must be a JSON object.');
                }

                foreach (is_array($raw->workouts ?? null) ? $raw->workouts : [] as $index => $workout) {
                    if ($workout instanceof stdClass && isset($workout->_formatting) && ! $workout->_formatting instanceof stdClass) {
                        $validator->errors()->add("raw.workouts.{$index}._formatting", 'The formatting must be a JSON object.');
                    }
                }

                if (strlen($this->rawJson()) > PrismAthletePublication::RAW_MAX_BYTES) {
                    $validator->errors()->add('raw', 'The raw file may not be larger than 2 MB.');
                }
            },
        ];
    }

    /**
     * The raw file as sent, with JSON objects kept as objects so `{}` survives storage.
     */
    public function rawFile(): mixed
    {
        $body = json_decode($this->getContent(), false);

        return $body instanceof stdClass ? ($body->raw ?? null) : null;
    }

    /**
     * The raw file re-encoded as the JSON text to store.
     */
    public function rawJson(): string
    {
        return (string) json_encode($this->rawFile(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
