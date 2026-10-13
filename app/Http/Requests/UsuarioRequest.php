<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('usuario')?->id;

        return [
            'name' => [$id ? 'sometimes' : 'required', 'string', 'max:120'],
            'email' => [$id ? 'sometimes' : 'required', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($id)],
            'rol_id' => [$id ? 'sometimes' : 'required', 'integer', 'exists:roles,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
