<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class SubirCargaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // mimes valida el contenido real (MIME), no solo la extensión
            'archivo' => ['required', 'file', 'mimes:xlsx,xls', 'max:'.config('reportes.etl.max_kb', 5120)],
            'anio' => ['nullable', 'integer', 'between:2020,2100'],
            'hoja' => ['nullable', 'string', 'max:31'],   // 31 = largo máximo de un nombre de hoja en Excel
            'periodo_inicio' => ['nullable', 'required_with:periodo_fin', 'date_format:d/m/Y'],
            'periodo_fin' => ['nullable', 'required_with:periodo_inicio', 'date_format:d/m/Y'],
        ];
    }

    public function messages(): array
    {
        return [
            'archivo.mimes' => 'Archivo incorrecto: solo se aceptan archivos de Excel (.xlsx). Word, PowerPoint y .odt no son válidos.',
            'archivo.max' => 'El archivo supera el tamaño máximo permitido (5 MB).',
        ];
    }

    public function periodoManual(): ?array
    {
        if (! $this->filled('periodo_inicio')) {
            return null;
        }

        $inicio = Carbon::createFromFormat('!d/m/Y', $this->input('periodo_inicio'));
        $fin = Carbon::createFromFormat('!d/m/Y', $this->input('periodo_fin'));
        if ($fin->lt($inicio)) {
            throw ValidationException::withMessages(['periodo_fin' => 'La fecha final debe ser igual o posterior a la inicial.']);
        }

        return ['inicio' => $inicio->format('Y-m-d'), 'fin' => $fin->format('Y-m-d')];
    }
}
