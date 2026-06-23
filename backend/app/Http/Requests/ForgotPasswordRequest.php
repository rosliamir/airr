<?php

namespace App\Http\Requests;

// FR-M1.1: password reset request. Always answered generically (no account
// enumeration) regardless of whether the email exists.
class ForgotPasswordRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
        ];
    }
}
