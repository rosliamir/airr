<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// All AIRR form requests extend this so validation errors render via the
// standard error envelope (mapped in bootstrap/app.php).
abstract class BaseFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
