<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ProvisionVpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => 'required|integer|min:1|max:20',
            // 'captcha' provisions a solver node instead of a bot worker. Defaults to 'bot'
            // so an existing caller keeps its behaviour.
            'role' => 'nullable|in:bot,captcha',
            'profile' => 'nullable|in:dedicated,shared',
        ];
    }
}
