<?php

use App\Enums\Permiso as P;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CargaController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\ColumnaController;
use App\Http\Controllers\Api\EmpleadoController;
use App\Http\Controllers\Api\ExportacionController;
use App\Http\Controllers\Api\HistoricoController;
use App\Http\Controllers\Api\RegistroController;
use App\Http\Controllers\Api\RolController;
use App\Http\Controllers\Api\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
| Autorización REAL en el servidor: cada ruta declara su permiso.
| El frontend solo oculta botones; nunca es la barrera de seguridad.
*/

// ---------- Públicas ----------
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('olvide', [AuthController::class, 'olvide'])->middleware('throttle:recuperacion');
    Route::post('restablecer', [AuthController::class, 'restablecer'])->middleware('throttle:recuperacion');
});

// ---------- Autenticadas ----------
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::put('auth/password', [AuthController::class, 'cambiarPassword']);

    Route::middleware('password.cambiada')->group(function () {
        // Catálogos
        Route::get('puestos', [CatalogoController::class, 'puestos'])->can(P::RegistrosVer->value);
        Route::get('periodos', [CatalogoController::class, 'periodos'])->can(P::RegistrosVer->value);

        // Dashboard (una fila = un empleado en una quincena)
        Route::get('registros', [RegistroController::class, 'index'])->can(P::RegistrosVer->value);
        Route::get('registros/{registro}/resumen', [RegistroController::class, 'resumen'])->can(P::RegistrosVer->value);
        Route::patch('registros/{registro}', [RegistroController::class, 'actualizar'])->can(P::RegistrosEditar->value);
        Route::delete('registros/{registro}', [RegistroController::class, 'destroy'])->can(P::RegistrosEliminar->value);
        Route::post('empleados', [EmpleadoController::class, 'store'])->can(P::RegistrosCrear->value);

        // ETL
        Route::get('cargas', [CargaController::class, 'index'])->can(P::CargasVer->value);
        Route::middleware(['can:'.P::CargasSubir->value])->group(function () {
            Route::post('cargas', [CargaController::class, 'store'])->middleware('throttle:cargas');
            Route::get('cargas/{carga}', [CargaController::class, 'show']);
            Route::get('cargas/{carga}/previsualizar', [CargaController::class, 'previsualizar']);
            Route::post('cargas/{carga}/aplicar', [CargaController::class, 'aplicar']);
            Route::post('cargas/{carga}/descartar', [CargaController::class, 'descartar']);
        });

        // Exportación
        Route::get('exportaciones', [ExportacionController::class, 'index'])->can(P::Exportar->value);
        Route::post('exportaciones', [ExportacionController::class, 'store'])->can(P::Exportar->value);
        Route::get('exportaciones/{informe}/descargar', [ExportacionController::class, 'descargar'])->can(P::Exportar->value);

        // Histórico (consultar no restaura)
        Route::get('historico', [HistoricoController::class, 'index'])->can(P::HistoricoVer->value);
        Route::post('historico/{id}/restaurar', [HistoricoController::class, 'restaurar'])->can(P::HistoricoRestaurar->value);

        // Columnas del reporte
        Route::get('columnas', [ColumnaController::class, 'index'])->can(P::RegistrosVer->value);
        Route::middleware('can:'.P::ColumnasGestionar->value)->group(function () {
            Route::post('columnas', [ColumnaController::class, 'store']);
            Route::patch('columnas/{columna}', [ColumnaController::class, 'update']);
            Route::post('columnas/{columna}/mover', [ColumnaController::class, 'mover']);
            Route::patch('columnas/{columna}/tipo', [ColumnaController::class, 'cambiarTipo']);
            Route::delete('columnas/{columna}', [ColumnaController::class, 'destroy']);
        });

        // Administración
        Route::middleware('can:'.P::UsuariosGestionar->value)->group(function () {
            Route::get('roles', [RolController::class, 'index']);
            Route::put('roles/{rol}/permisos', [RolController::class, 'actualizarPermisos']);
            Route::apiResource('usuarios', UsuarioController::class)->except('show');
            Route::post('usuarios/{usuario}/desbloquear', [UsuarioController::class, 'desbloquear']);
        });
        Route::get('ajustes', [CatalogoController::class, 'ajustes'])->can(P::AjustesGestionar->value);
        Route::put('ajustes', [CatalogoController::class, 'guardarAjustes'])->can(P::AjustesGestionar->value);
    });
});
