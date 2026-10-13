<?php

/*
|--------------------------------------------------------------------------
| Configuración de negocio de la plataforma de reportes
|--------------------------------------------------------------------------
| Todo lo que RH podría querer cambiar sin tocar código vive aquí (o en la
| tabla `ajustes` si debe cambiarse desde la pantalla "Ajustes").
*/

return [

    // Número de empleado: MSI000001, MSI000002, ...  (prefijo + 6 dígitos, confirmado por RH)
    'numero_empleado' => [
        'prefijo' => env('NUM_EMPLEADO_PREFIJO', 'MSI'),
        'digitos' => (int) env('NUM_EMPLEADO_DIGITOS', 6),
    ],

    // Seguridad de acceso
    'seguridad' => [
        'max_intentos' => (int) env('LOGIN_MAX_INTENTOS', 5),
        'minutos_bloqueo' => (int) env('LOGIN_MINUTOS_BLOQUEO', 15),
    ],

    // ETL
    'etl' => [
        'filas_esperadas' => 48,           // solo genera ADVERTENCIA si no coincide
        'max_kb' => 5120,                  // 5 MB
        'disco' => 'local',                // storage/app/private
        'carpeta' => 'cargas',
        'max_faltas' => 31,
    ],

    // Puestos sugeridos para el catálogo (el Excel trae "Puesto" en la 3a columna; el catálogo crece solo)
    'puestos_base' => ['Asesor', 'Supervisor', 'MSI'],
];
