<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;

// FR-M1.1: self-registration. Creates a PENDING account that a project admin
// must approve and assign a role before any access is granted.
class RegisterRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:120',
            'email'    => 'required|email|max:190|unique:users,email',
            'password' => ['required', 'string', Password::min(8)],
        ];
    }
}
