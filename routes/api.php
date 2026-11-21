<?php

use App\Enums\Permiso as P;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CfdiController;
use App\Http\Controllers\Api\EmpleadoController;
use App\Http\Controllers\Api\NominaConfigController;
use App\Http\Controllers\Api\NominaController;
use App\Http\Controllers\Api\AjusteAnualController;
use App\Http\Controllers\Api\VacacionesController;
use App\Http\Controllers\Api\PublicacionController;
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
        // v11: Empleados (expediente) — consultar: empleados.ver; cambiar: empleados.gestionar
        Route::middleware('can:'.P::EmpleadosVer->value)->group(function () {
            Route::get('empleados', [EmpleadoController::class, 'index']);
            Route::get('empleados/alertas', [EmpleadoController::class, 'alertas']);
            Route::get('empleados/{empleado}', [EmpleadoController::class, 'show']);
            Route::get('empleados/{empleado}/simular', [EmpleadoController::class, 'simular']);
            Route::get('empleados/{empleado}/vacaciones', [VacacionesController::class, 'index']);   // v13
            Route::get('puestos', [NominaConfigController::class, 'puestos']);
            Route::get('nomina/empresa', [NominaConfigController::class, 'empresa']);
            Route::get('nomina/conceptos', [NominaConfigController::class, 'conceptos']);
            Route::get('nomina/tablas', [NominaConfigController::class, 'tablas']);
            Route::get('nomina/periodos', [NominaConfigController::class, 'periodos']);
        });
        Route::middleware('can:'.P::EmpleadosGestionar->value)->group(function () {
            Route::post('empleados', [EmpleadoController::class, 'store']);
            Route::patch('empleados/{empleado}', [EmpleadoController::class, 'update']);
            Route::post('empleados/{empleado}/baja', [EmpleadoController::class, 'baja']);
            Route::post('empleados/{empleado}/reactivar', [EmpleadoController::class, 'reactivar']);
            Route::post('empleados/{empleado}/salario', [EmpleadoController::class, 'salario']);
            Route::post('empleados/{empleado}/contrato', [EmpleadoController::class, 'contrato']);
            Route::post('empleados/{empleado}/vacaciones', [VacacionesController::class, 'store']);   // v13
            Route::delete('vacaciones/{vacacion}', [VacacionesController::class, 'destroy']);
            Route::post('puestos', [NominaConfigController::class, 'crearPuesto']);
        });
        // v11: Configuración de nómina
        // v12: nómina del periodo, historial, reportes, respaldos e indicadores
        Route::middleware('can:'.P::NominaVer->value)->group(function () {
            Route::get('nomina/historial', [NominaController::class, 'historial']);
            Route::get('nomina/recibos', [NominaController::class, 'recibos']);
            Route::get('nomina/recibos/{recibo}', [NominaController::class, 'recibo']);
            Route::get('nomina/reportes/lista-de-raya/{periodo}', [NominaController::class, 'listaDeRaya']);
            Route::get('nomina/reportes/acumulados', [NominaController::class, 'acumulados']);
            // v13: recibos en PDF y cálculo anual
            Route::get('nomina/recibos/{recibo}/pdf', [NominaController::class, 'pdf']);
            Route::get('nomina/periodos/{periodo}/recibos-pdf', [NominaController::class, 'pdfPeriodo']);
            Route::get('nomina/periodos/{periodo}/envios', [NominaController::class, 'envios']);
            Route::get('nomina/ajuste-anual', [AjusteAnualController::class, 'index']);
            Route::get('nomina/recibos/{recibo}/xml', [CfdiController::class, 'xml']);   // v14
        });
        // v14: CFDI de nómina (timbrado opcional, CSD, PAC, cancelación e importación de XML)
        Route::middleware('can:'.P::NominaTimbrar->value)->group(function () {
            Route::get('nomina/cfdi/configuracion', [CfdiController::class, 'configuracion']);
            Route::put('nomina/cfdi/configuracion', [CfdiController::class, 'guardarConfiguracion']);
            Route::post('nomina/cfdi/csd', [CfdiController::class, 'cargarCsd']);
            Route::post('nomina/cfdi/probar', [CfdiController::class, 'probar']);
            Route::get('nomina/cfdi/recibos/{recibo}/validar', [CfdiController::class, 'validar']);
            Route::post('nomina/cfdi/recibos/{recibo}/timbrar', [CfdiController::class, 'timbrarRecibo']);
            Route::post('nomina/cfdi/recibos/{recibo}/cancelar', [CfdiController::class, 'cancelar']);
            Route::post('nomina/cfdi/periodos/{periodo}/timbrar', [CfdiController::class, 'timbrarPeriodo']);
            Route::post('nomina/cfdi/importar', [CfdiController::class, 'importar']);
            Route::get('nomina/cfdi/eventos', [CfdiController::class, 'eventos']);
        });
        Route::middleware('can:'.P::NominaCalcular->value)->group(function () {
            Route::put('nomina/periodos/{periodo}/incidencias', [NominaController::class, 'incidencias']);
            Route::post('nomina/periodos/{periodo}/calcular', [NominaController::class, 'calcular']);
            Route::post('nomina/aguinaldo', [NominaController::class, 'crearAguinaldo']);   // v13
        });
        Route::middleware('can:'.P::NominaAutorizar->value)->group(function () {
            Route::post('nomina/periodos/{periodo}/autorizar', [NominaController::class, 'autorizar']);
            Route::post('nomina/periodos/{periodo}/reabrir', [NominaController::class, 'reabrir']);
            Route::get('nomina/respaldos', [NominaController::class, 'respaldos']);
            Route::post('nomina/respaldos', [NominaController::class, 'crearRespaldo']);
            Route::get('nomina/respaldos/{archivo}', [NominaController::class, 'descargarRespaldo'])->where('archivo', 'respaldo-[\w-]+\.sql');
            Route::post('nomina/reconstruir', [NominaController::class, 'reconstruir']);
            // v13: envío de recibos por correo y cálculo anual
            Route::post('nomina/recibos/{recibo}/enviar', [NominaController::class, 'enviarRecibo']);
            Route::post('nomina/periodos/{periodo}/enviar-recibos', [NominaController::class, 'enviarPeriodo']);
            Route::post('nomina/ajuste-anual/calcular', [AjusteAnualController::class, 'calcular']);
            Route::patch('nomina/ajuste-anual/{ajuste}', [AjusteAnualController::class, 'declaracion']);
            Route::post('nomina/ajuste-anual/aplicar', [AjusteAnualController::class, 'aplicar']);
            Route::post('nomina/ajuste-anual/quitar', [AjusteAnualController::class, 'quitar']);
        });
        Route::get('indicadores', [NominaController::class, 'indicadores'])->can(P::IndicadoresVer->value);
        Route::get('nomina/periodos/{periodo}/captura', [NominaController::class, 'captura']);   // nomina.ver O nomina.calcular (se revisa en el controlador)

        Route::middleware('can:'.P::NominaConfigurar->value)->group(function () {
            Route::put('nomina/empresa', [NominaConfigController::class, 'guardarEmpresa']);
            Route::post('nomina/conceptos', [NominaConfigController::class, 'crearConcepto']);
            Route::patch('nomina/conceptos/{concepto}', [NominaConfigController::class, 'actualizarConcepto']);
            Route::delete('nomina/conceptos/{concepto}', [NominaConfigController::class, 'eliminarConcepto']);
            Route::post('nomina/tablas', [NominaConfigController::class, 'guardarTabla']);
            Route::delete('nomina/tablas/{tabla}', [NominaConfigController::class, 'eliminarTabla']);
            Route::post('nomina/periodos/generar', [NominaConfigController::class, 'generarPeriodos']);
            Route::patch('nomina/periodos/{periodo}', [NominaConfigController::class, 'actualizarPeriodo']);
        });

        // v10: Publicaciones (la visibilidad por audiencia y la edición por autor/moderador se revisan en el controlador)
        Route::middleware('can:'.P::PublicacionesVer->value)->group(function () {
            Route::get('publicaciones', [PublicacionController::class, 'index']);
            Route::get('publicaciones/sin-leer', [PublicacionController::class, 'sinLeer']);
            Route::get('publicaciones/categorias', [PublicacionController::class, 'categorias']);
            Route::get('publicaciones/{id}', [PublicacionController::class, 'show'])->whereNumber('id');
            Route::post('publicaciones/{id}/favorita', [PublicacionController::class, 'favorita'])->whereNumber('id');
            Route::get('publicaciones/{id}/adjuntos/{adjunto}', [PublicacionController::class, 'adjunto'])->whereNumber('id');
        });
        Route::middleware('can:'.P::PublicacionesCrear->value)->group(function () {
            Route::get('publicaciones/audiencias', [PublicacionController::class, 'audiencias']);
            Route::post('publicaciones', [PublicacionController::class, 'store'])->middleware('throttle:cargas');
        });
        // Autor o moderador (se revisa en el controlador)
        Route::middleware('can:'.P::PublicacionesVer->value)->group(function () {
            Route::patch('publicaciones/{id}', [PublicacionController::class, 'update'])->whereNumber('id');
            Route::delete('publicaciones/{id}', [PublicacionController::class, 'destroy'])->whereNumber('id');
            Route::post('publicaciones/{id}/publicar', [PublicacionController::class, 'publicar'])->whereNumber('id');
            Route::post('publicaciones/{id}/archivar', [PublicacionController::class, 'archivar'])->whereNumber('id');
            Route::post('publicaciones/{id}/adjuntos', [PublicacionController::class, 'agregarAdjuntos'])->whereNumber('id')->middleware('throttle:cargas');
            Route::delete('publicaciones/{id}/adjuntos/{adjunto}', [PublicacionController::class, 'quitarAdjunto'])->whereNumber('id');
            Route::get('publicaciones/{id}/lecturas', [PublicacionController::class, 'lecturas'])->whereNumber('id');
        });
        Route::post('publicaciones/{id}/moderar', [PublicacionController::class, 'moderar'])->whereNumber('id')->can(P::PublicacionesModerar->value);

        // Administración
        Route::middleware('can:'.P::UsuariosGestionar->value)->group(function () {
            Route::get('roles', [RolController::class, 'index']);
            Route::put('roles/{rol}/permisos', [RolController::class, 'actualizarPermisos']);
            Route::apiResource('usuarios', UsuarioController::class)->except('show');
            Route::post('usuarios/{usuario}/desbloquear', [UsuarioController::class, 'desbloquear']);
        });
    });
});
