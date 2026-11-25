<?php

namespace App\Sync\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class RevokePrismCoachPublicationsRequest extends FormRequest
{
    /** Only an admin may revoke a coach publication. */
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
            'recipient_email' => ['required', 'string', 'email', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:36'],
        ];
    }

    /**
     * The existing Logger account named by `recipient_email`, or null when there is none.
     */
    public function recipient(): ?User
    {
        return User::query()->where('email', $this->validated('recipient_email'))->first();
    }
}
