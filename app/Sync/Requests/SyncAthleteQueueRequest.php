<?php

namespace App\Sync\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

class SyncAthleteQueueRequest extends FormRequest
{
    public const MAX_ITEMS = 200;

    public const MAX_ID_LENGTH = 191;

    /** ISO-8601 date and time with a zone, as JavaScript's `toISOString()` writes it. */
    private const ISO_8601_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,9})?)?(?:Z|[+-]\d{2}:?\d{2})$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * A body that can't be read parses as no input at all, which would pass as an empty push, so it is
     * refused here.
     */
    protected function prepareForValidation(): void
    {
        $body = json_decode($this->getContent());

        if (! $this->isJson() || ! is_object($body)) {
            throw ValidationException::withMessages(['body' => 'The body must be a JSON object.']);
        }

        if (isset($body->items) && ! is_array($body->items)) {
            throw ValidationException::withMessages(['items' => 'The items field must be a list.']);
        }

        foreach (['items', 'deletedIds'] as $key) {
            if ($this->input($key) === null) {
                $this->merge([$key => []]);
            }
        }
    }

    /**
     * A coach item is skipped, never refused, so its `id` and `updatedAt` are not checked.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'list', 'max:'.self::MAX_ITEMS],
            'items.*' => ['array'],
            'items.*.id' => ['exclude_if:items.*.source,coach', 'required', 'string', 'max:'.self::MAX_ID_LENGTH, $this->uniqueAmongStoredItems(...)],
            'items.*.updatedAt' => ['exclude_if:items.*.source,coach', 'required', 'string', $this->isoDateTime(...)],
            'deletedIds' => ['present', 'array', 'list'],
            'deletedIds.*' => ['string', 'max:'.self::MAX_ID_LENGTH],
        ];
    }

    /** Laravel's `distinct` also counts coach items, and compares "1" and "01" as equal. */
    private function uniqueAmongStoredItems(string $attribute, mixed $value, Closure $fail): void
    {
        $matches = array_filter(
            $this->input('items'),
            fn (mixed $item): bool => is_array($item) && ($item['source'] ?? null) !== 'coach' && ($item['id'] ?? null) === $value,
        );

        if (count($matches) > 1) {
            $fail('The :attribute field has a duplicate value.');
        }
    }

    private function isoDateTime(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isIsoDateTime($value)) {
            $fail('The :attribute must be an ISO-8601 date and time.');
        }
    }

    private static function isIsoDateTime(string $value): bool
    {
        if (preg_match(self::ISO_8601_PATTERN, $value, $parts) !== 1) {
            return false;
        }

        [, $year, $month, $day, $hour, $minute] = $parts;
        $second = $parts[6] ?? '00';

        if (! checkdate((int) $month, (int) $day, (int) $year) || (int) $hour > 23 || (int) $minute > 59 || (int) $second > 59) {
            return false;
        }

        try {
            Carbon::parse($value);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * The pushed items, each with the JSON text it is stored as. The text is re-encoded from the raw
     * body, not from Laravel's parsed input, so an empty object stays `{}` and `1.0` stays `1.0`.
     *
     * @return list<array{id: string, updatedAt: string, source: mixed, json: string}>
     */
    public function queueItems(): array
    {
        $rawItems = json_decode($this->getContent())->items ?? [];
        $queueItems = [];

        foreach ($this->input('items', []) as $index => $item) {
            $queueItems[] = [
                'id' => (string) ($item['id'] ?? ''),
                'updatedAt' => (string) ($item['updatedAt'] ?? ''),
                'source' => $item['source'] ?? null,
                'json' => json_encode(
                    $rawItems[$index],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
                ),
            ];
        }

        return $queueItems;
    }

    /**
     * @return list<string>
     */
    public function deletedIds(): array
    {
        return array_values(array_map('strval', $this->input('deletedIds', [])));
    }
}
