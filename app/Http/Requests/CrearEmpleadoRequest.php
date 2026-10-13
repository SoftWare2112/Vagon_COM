<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class CrearEmpleadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre_completo' => ['required', 'string', 'max:150', "regex:/^[\pL][\pL .'\-]*$/u"],
            'puesto' => ['nullable', 'string', 'max:100'],
            'fecha_ingreso' => ['required', 'date_format:d/m/Y', 'before_or_equal:today'],
            'periodo_id' => ['nullable', 'integer', 'exists:periodos,id'],
            'valores' => ['nullable', 'array'],   // resto de columnas {clave: valor}
        ];
    }

    /** dd/mm/aaaa (lo que escribe RH) -> Y-m-d (lo que guarda MySQL). */
    public function datos(): array
    {
        $d = $this->validated();
        $d['fecha_ingreso'] = Carbon::createFromFormat('!d/m/Y', $d['fecha_ingreso'])->format('Y-m-d');

        return $d;
    }
}
