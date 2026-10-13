<?php

namespace Tests\Unit\Etl;

use App\Services\Etl\Dto\HojaExtraida;
use App\Services\Etl\Dto\Mensaje;
use App\Services\Etl\Dto\ResultadoProcesamiento;
use App\Services\Etl\ProcesadorHoja;
use App\Support\CatalogoColumnas;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del pipeline ETL con los encabezados confirmados por RH (formato de octubre 2026). No necesitan base de datos.
 */
class ProcesadorHojaTest extends TestCase
{
    /** Formato confirmado por RH (octubre 2026). */
    private const ENCABEZADOS = [
        'A' => 'Nombre', 'B' => 'Fecha de ingreso', 'C' => 'Puesto', 'D' => 'Prima vacacional', 'E' => 'Bono 3 meses',
        'F' => 'Bono puntualidad', 'G' => 'Bono asistencia', 'H' => 'Numero de faltas', 'I' => 'Detalles',
        'J' => 'Acredita para bono puntualidad', 'K' => 'Programado en Domingo', 'L' => 'Domingo 4', 'M' => 'Domingo 11',
        'N' => '1 de octubre al 13 de octubre de 2026',
    ];

    private function fila(int $n, array $cambios = []): array
    {
        $base = [
            'A' => 'Reynaldo Ivan Antonio Ponce', 'B' => '15/01/2018', 'C' => 'Asesor', 'D' => '$1,200.00', 'E' => '$600.00',
            'F' => '$500.00', 'G' => '$300.00', 'H' => 1, 'I' => 'Falta el 07/10', 'J' => 1,
            'K' => 1, 'L' => 1, 'M' => null, 'N' => '1 domingo pagado',
        ];

        return ['fila' => $n, 'celdas' => array_merge($base, $cambios)];
    }

    private function procesar(array $filas, ?array $encabezados = null, ?array $catalogo = null): ResultadoProcesamiento
    {
        return (new ProcesadorHoja(catalogo: $catalogo, filasEsperadas: 0))
            ->procesar(new HojaExtraida($encabezados ?? self::ENCABEZADOS, $filas), 2026);
    }

    private function codigos(array $mensajes): array
    {
        return array_map(fn (Mensaje $m) => $m->codigo, $mensajes);
    }

    public function test_fila_valida_se_transforma_a_tipos_reales(): void
    {
        $r = $this->procesar([$this->fila(2)]);

        $this->assertFalse($r->tieneErrores(), json_encode(array_map(fn ($m) => $m->toArray(), $r->mensajes)));
        $d = $r->filas[0]['datos'];
        $this->assertSame('2018-01-15', $d['fecha_ingreso']);
        $this->assertSame(1200.0, $d['prima_vacacional']);
        $this->assertSame(500.0, $d['bono_puntualidad']);
        $this->assertSame(300.0, $d['bono_asistencia']);
        $this->assertSame(1, $d['num_faltas']);
        $this->assertTrue($d['acredita_bono']);
        $this->assertSame('reynaldo ivan antonio ponce', $d['nombre_normalizado']);
        $this->assertSame('Asesor', $d['puesto']);
        $this->assertSame('2026-10-04', $d['domingos'][0]['fecha']);
        $this->assertSame(1.0, $d['domingos'][0]['cantidad']);
        $this->assertNull($d['domingos'][1]['cantidad']);              // celda vacía se respeta (no se calcula)
        $this->assertSame('1 domingo pagado', $d['valor_periodo']);    // texto tal cual
        $this->assertSame('2026-10-01', $r->periodo->inicio->format('Y-m-d'));
        $this->assertSame('2026-10-13', $r->periodo->fin->format('Y-m-d'));
        $this->assertSame(2026, $r->periodo->anioEncabezado);
    }

    public function test_sin_columna_puesto_solo_advierte(): void
    {
        $enc = self::ENCABEZADOS;
        unset($enc['C']);
        $r = $this->procesar([$this->fila(2)], $enc);
        $this->assertFalse($r->tieneErrores());
        $this->assertSame(['COLUMNA_AUSENTE'], $this->codigos($r->advertencias()));
        $this->assertArrayNotHasKey('puesto', $r->filas[0]['datos']);
    }

    public function test_periodo_sin_anio_usa_el_del_formulario(): void
    {
        $enc = self::ENCABEZADOS;
        $enc['N'] = '1 de octubre al 13 de octubre';
        $r = $this->procesar([$this->fila(2)], $enc);
        $this->assertSame('2026-10-01', $r->periodo->inicio->format('Y-m-d'));
        $this->assertNull($r->periodo->anioEncabezado);
    }

    public function test_fecha_como_numero_de_serie_de_excel(): void
    {
        $r = $this->procesar([$this->fila(2, ['B' => 43115])]);   // 15/01/2018 en Excel
        $this->assertSame('2018-01-15', $r->filas[0]['datos']['fecha_ingreso']);
    }

    public function test_celda_vacia_obligatoria_reporta_celda_exacta(): void
    {
        $r = $this->procesar([$this->fila(14, ['A' => '  '])]);
        $err = $r->errores()[0];
        $this->assertSame('CELDA_VACIA', $err->codigo);
        $this->assertSame('A14', $err->celda);
    }

    public function test_tipo_invalido_en_faltas(): void
    {
        $r = $this->procesar([$this->fila(9, ['H' => 'dos'])]);
        $this->assertSame(['TIPO_INVALIDO'], $this->codigos($r->errores()));
        $this->assertSame('H9', $r->errores()[0]->celda);
    }

    public function test_caracter_invalido(): void
    {
        $r = $this->procesar([$this->fila(20, ['I' => "Falt\x07a"])]);
        $this->assertSame('CARACTER_INVALIDO', $r->errores()[0]->codigo);
        $this->assertSame('I20', $r->errores()[0]->celda);
    }

    public function test_fecha_invalida(): void
    {
        $r = $this->procesar([$this->fila(5, ['B' => '31/02/2020'])]);
        $this->assertSame('FECHA_INVALIDA', $r->errores()[0]->codigo);
    }

    public function test_duplicado_en_archivo(): void
    {
        $r = $this->procesar([$this->fila(7), $this->fila(31, ['A' => 'REYNALDO  Iván Antonio Ponce'])]);
        $this->assertContains('DUPLICADO_EN_ARCHIVO', $this->codigos($r->errores()));
    }

    public function test_no_se_calcula_nada_entre_programado_y_domingos(): void
    {
        // Programado 2, pagado 0: RH pidió leer tal cual, sin advertencias calculadas
        $r = $this->procesar([$this->fila(3, ['K' => 2, 'L' => 0, 'M' => 0])]);
        $this->assertSame([], $r->mensajes);
    }

    public function test_falta_columna_obligatoria_es_error(): void
    {
        $enc = self::ENCABEZADOS;
        unset($enc['B']);
        $r = $this->procesar([$this->fila(2)], $enc);
        $this->assertContains('ENCABEZADO_FALTANTE', $this->codigos($r->errores()));
    }

    public function test_archivo_con_columnas_de_menos_solo_advierte_y_no_las_incluye(): void
    {
        $enc = self::ENCABEZADOS;
        unset($enc['G'], $enc['I']);    // sin Bono asistencia ni Detalles
        $r = $this->procesar([$this->fila(2)], $enc);

        $this->assertFalse($r->tieneErrores());
        $this->assertSame(['COLUMNA_AUSENTE', 'COLUMNA_AUSENTE'], $this->codigos($r->advertencias()));
        $this->assertArrayNotHasKey('bono_asistencia', $r->filas[0]['datos']);   // no se tocará al aplicar
        $this->assertArrayNotHasKey('detalle', $r->filas[0]['datos']);
        $this->assertContains('bono_asistencia', $r->mapa->ausentes);
    }

    public function test_columna_nueva_personalizada_se_reconoce_en_cualquier_posicion(): void
    {
        $catalogo = [...CatalogoColumnas::SISTEMA, ['clave' => 'bono_transporte', 'etiqueta' => 'Bono transporte', 'tipo' => 'dinero', 'origen' => 'personalizada', 'alias' => []]];
        // RH insertó "Bono transporte" en la columna B y movió "Fecha de ingreso" al final
        $enc = self::ENCABEZADOS;
        $enc['B'] = 'Bono transporte';
        $enc['O'] = 'Fecha de ingreso';
        $fila = $this->fila(2, ['B' => '$250.00', 'O' => '15/01/2018']);

        $r = $this->procesar([$fila], $enc, $catalogo);
        $this->assertFalse($r->tieneErrores(), json_encode(array_map(fn ($m) => $m->toArray(), $r->mensajes)));
        $this->assertSame(250.0, $r->filas[0]['datos']['extras']['bono_transporte']);
        $this->assertSame('2018-01-15', $r->filas[0]['datos']['fecha_ingreso']);
    }

    public function test_columna_desconocida_se_reporta_con_su_nombre(): void
    {
        $enc = self::ENCABEZADOS + ['O' => 'Vales de despensa'];
        $r = $this->procesar([$this->fila(2)], $enc);
        $adv = array_values(array_filter($r->advertencias(), fn (Mensaje $m) => $m->codigo === 'ENCABEZADO_DESCONOCIDO'));
        $this->assertSame('Vales de despensa', $adv[0]->valorRecibido);
    }

    public function test_mismo_contenido_mismo_hash(): void
    {
        $a = $this->procesar([$this->fila(2), $this->fila(3, ['A' => 'Ana López'])]);
        $b = $this->procesar([$this->fila(3, ['A' => 'Ana López']), $this->fila(2)]);
        $this->assertSame($a->contenidoHash(), $b->contenidoHash());
    }

    public function test_periodo_no_detectado(): void
    {
        $enc = self::ENCABEZADOS;
        unset($enc['N']);
        $r = $this->procesar([$this->fila(2)], $enc);
        $this->assertContains('PERIODO_NO_DETECTADO', $this->codigos($r->errores()));
    }
}
