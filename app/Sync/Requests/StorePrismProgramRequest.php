<?php

namespace App\Sync\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Partial program write: only the fields present are written; an explicit null clears.
 * name + week_count are required on create, which UpsertPrismProgramAction enforces
 * (it knows whether the row exists).
 */
class StorePrismProgramRequest extends FormRequest
{
    /** Seconds an old-format client is told to wait before retrying (§6.3). */
    public const LEGACY_RETRY_AFTER_SECONDS = 300;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Cutover guard — docs/plans/prism-program-partial-updates.md §6.3.
     *
     * The metadata blob is gone. A write carrying it comes from a Prism tab still running the
     * pre-cutover build. Answer 503 (not 422): Prism's sync queue drops 4xx edits but keeps and
     * retries 5xx ones, so the edit survives until the coach reloads, when the new build rewrites
     * the queued item into the new format. Remove this guard once the log line stops appearing.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('metadata')) {
            Log::warning('prism.program.legacy_metadata_write', [
                'gym' => $this->route('gym'),
                'program_uuid' => $this->route('program_uuid'),
                'device_id' => $this->attributes->get('device_id'),
            ]);

            throw new HttpException(
                503,
                'This Prism version is out of date. Reload Prism to sync this change.',
                null,
                ['Retry-After' => (string) self::LEGACY_RETRY_AFTER_SECONDS],
            );
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'week_count' => ['sometimes', 'required', 'integer', 'min:0', 'max:65535'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'archived_at' => ['sometimes', 'nullable', 'date'],
            'tab_position' => ['bail', 'sometimes', 'nullable', 'string', 'max:64', 'ascii'],
            'device_id' => ['nullable', 'string', 'max:36'],
        ];
    }
}
