<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;

// FR-M1.1: complete a password reset via the broker token e-mailed to the user.
class ResetPasswordRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }
}
