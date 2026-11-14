<?php

use App\Enums\Permiso as P;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CargaController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\ColumnaController;
use App\Http\Controllers\Api\EmpleadoController;
use App\Http\Controllers\Api\EstadisticaController;
use App\Http\Controllers\Api\ExportacionController;
use App\Http\Controllers\Api\HistoricoController;
use App\Http\Controllers\Api\HojaController;
use App\Http\Controllers\Api\HojaPlantillaController;
use App\Http\Controllers\Api\LibroController;
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
        Route::get('registros/impresion', [RegistroController::class, 'impresion'])->can(P::RegistrosVer->value);   // v6: botón Imprimir
        Route::get('estadisticas', [EstadisticaController::class, 'index'])->can(P::RegistrosVer->value);          // v6: Gráficas
        Route::get('registros/{registro}/resumen', [RegistroController::class, 'resumen'])->can(P::RegistrosVer->value);
        Route::patch('registros/{registro}', [RegistroController::class, 'actualizar'])->can(P::RegistrosEditar->value);
        Route::delete('registros/{registro}', [RegistroController::class, 'destroy'])->can(P::RegistrosEliminar->value);
        Route::post('empleados', [EmpleadoController::class, 'store'])->can(P::RegistrosCrear->value);

        // ETL · v9: historial de TODAS las importaciones (nómina y libros); la visibilidad se filtra en el controlador
        Route::get('cargas', [CargaController::class, 'index']);
        Route::post('cargas/limpiar', [CargaController::class, 'limpiar']);
        Route::post('cargas/{id}/restaurar', [CargaController::class, 'restaurar'])->whereNumber('id');
        Route::delete('cargas/{carga}', [CargaController::class, 'eliminar']);
        Route::middleware(['can:'.P::CargasSubir->value])->group(function () {
            Route::post('cargas', [CargaController::class, 'store'])->middleware('throttle:cargas');
            Route::get('cargas/{carga}', [CargaController::class, 'show']);
            Route::get('cargas/{carga}/previsualizar', [CargaController::class, 'previsualizar']);
            Route::post('cargas/{carga}/aplicar', [CargaController::class, 'aplicar']);
            Route::post('cargas/{carga}/descartar', [CargaController::class, 'descartar']);
            Route::get('cargas/{carga}/errores', [CargaController::class, 'errores']);   // v7: Excel con errores marcados
        });

        // Exportación
        Route::get('exportaciones', [ExportacionController::class, 'index'])->can(P::Exportar->value);
        Route::post('exportaciones', [ExportacionController::class, 'store'])->can(P::Exportar->value);
        Route::get('exportaciones/{informe}/descargar', [ExportacionController::class, 'descargar'])->can(P::Exportar->value);

        // Histórico (consultar no restaura)
        Route::get('historico', [HistoricoController::class, 'index'])->can(P::HistoricoVer->value);
        Route::post('historico/{id}/restaurar', [HistoricoController::class, 'restaurar'])->can(P::HistoricoRestaurar->value);

        // v8/v9: Hojas de cálculo en libros personales (cada usuario ve lo suyo; hojas.ver_todas ve todo)
        Route::get('hoja-plantillas', [HojaPlantillaController::class, 'index'])->can(P::HojasVer->value);
        Route::get('libros', [LibroController::class, 'index'])->can(P::HojasVer->value);
        Route::get('libros/personal', [LibroController::class, 'personal'])->can(P::HojasCrear->value);
        Route::get('libros/{libro}', [LibroController::class, 'show'])->can(P::HojasVer->value);
        Route::get('hojas/{hoja}', [HojaController::class, 'show'])->can(P::HojasVer->value);
        Route::get('hojas/{hoja}/imagenes/{imagen}', [HojaController::class, 'imagen'])->can(P::HojasVer->value);
        Route::middleware('can:'.P::HojasCrear->value)->group(function () {
            Route::post('libros', [LibroController::class, 'store']);
            Route::post('libros/importar', [LibroController::class, 'importar'])->middleware('throttle:cargas');
            Route::delete('libros/{libro}', [LibroController::class, 'destroy']);
        });
        Route::middleware('can:'.P::HojasEditar->value)->group(function () {
            Route::patch('libros/{libro}', [LibroController::class, 'update']);
            Route::post('libros/{libro}/hojas', [LibroController::class, 'agregarHoja']);
            Route::patch('hojas/{hoja}', [HojaController::class, 'update']);
            Route::delete('hojas/{hoja}', [HojaController::class, 'destroy']);
            Route::patch('hojas/{hoja}/celdas', [HojaController::class, 'celdas']);
            Route::post('hojas/{hoja}/filas', [HojaController::class, 'insertarFilas']);
            Route::delete('hojas/{hoja}/filas/{fila}', [HojaController::class, 'eliminarFila']);
            Route::post('hojas/{hoja}/columnas', [HojaController::class, 'insertarColumnas']);
            Route::patch('hojas/{hoja}/columnas/{columna}', [HojaController::class, 'anchoColumna']);
            Route::delete('hojas/{hoja}/columnas/{columna}', [HojaController::class, 'eliminarColumna']);
            Route::post('hojas/{hoja}/combinar', [HojaController::class, 'combinar']);
            Route::delete('hojas/{hoja}/combinadas/{combinada}', [HojaController::class, 'separar']);
            Route::delete('hojas/{hoja}/imagenes/{imagen}', [HojaController::class, 'eliminarImagen']);
        });

        // Columnas del reporte
        Route::get('columnas', [ColumnaController::class, 'index'])->can(P::RegistrosVer->value);
        Route::middleware('can:'.P::ColumnasGestionar->value)->group(function () {
            Route::post('columnas', [ColumnaController::class, 'store']);
            Route::patch('columnas/{columna}', [ColumnaController::class, 'update']);
            Route::post('columnas/{columna}/mover', [ColumnaController::class, 'mover']);
            Route::patch('columnas/{columna}/tipo', [ColumnaController::class, 'cambiarTipo']);
            Route::post('columnas/{columna}/alias', [ColumnaController::class, 'agregarAlias']);   // mapeo asistido
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
