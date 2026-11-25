<?php

namespace App\Sync\Requests;

use App\Models\User;
use App\Sync\Models\PrismAthletePublication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use stdClass;

class StorePrismCoachPublicationRequest extends FormRequest
{
    /** Only an admin may publish to an athlete. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole('Admin');
    }

    /**
     * `raw` is stored opaquely: only its outer shape (an object with `metadata` and `workouts`) is checked.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recipient_email' => ['required', 'string', 'email', 'max:255'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'raw' => ['required', 'array'],
            'raw.metadata' => ['present', 'array'],
            'raw.workouts' => ['present', 'array', 'list'],
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

                if (strlen($this->rawJson()) > PrismAthletePublication::RAW_MAX_BYTES) {
                    $validator->errors()->add('raw', 'The raw file may not be larger than 2 MB.');
                }
            },
        ];
    }

    /**
     * The existing Logger account named by `recipient_email`, or null when there is none.
     */
    public function recipient(): ?User
    {
        return User::query()->where('email', $this->validated('recipient_email'))->first();
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
