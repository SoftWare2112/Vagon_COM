<?php

namespace Tests\Unit\Etl;

use App\Services\Etl\HeaderMapper;
use App\Services\Etl\MetadatosHoja;
use App\Support\CatalogoColumnas;
use App\Support\Texto;
use PHPUnit\Framework\TestCase;

/**
 * v5: encabezados escritos a mano por RH (símbolos, abreviaturas, saltos de línea, otro orden)
 * y el bloque "Ejercicio / Periodo" de arriba de la tabla.
 */
class EncabezadosFlexiblesTest extends TestCase
{
    private function clave(string $encabezado): ?string
    {
        $c = (new HeaderMapper)->clasificar($encabezado);

        return $c['tipo'] === 'campo' ? $c['clave'] : $c['tipo'];
    }

    public function test_simbolos_de_numero(): void
    {
        foreach (['# de Faltas', '# Faltas', 'No. de faltas', 'N° de faltas', 'Nº faltas', 'Núm. de faltas', 'NUMERO DE FALTAS', "Numero\nde\nFaltas"] as $h) {
            $this->assertSame('num_faltas', $this->clave($h), $h);
        }
    }

    public function test_abreviaturas_con_punto(): void
    {
        $esperado = [
            'Nomb.' => 'nombre_completo', 'F. Ingr.' => 'fecha_ingreso', 'Fec. de ingreso' => 'fecha_ingreso', 'Pto.' => 'puesto',
            'Prima vac.' => 'prima_vacacional', 'Bono 3 m.' => 'bono_3_meses', 'Bono punt.' => 'bono_puntualidad',
            'Bono asist.' => 'bono_asistencia', 'Det.' => 'detalle', 'Acred. bono punt.' => 'acredita_bono',
            'Prog. en dom.' => 'programado_domingo', 'Obs.' => 'detalle',
        ];
        foreach ($esperado as $h => $clave) {
            $this->assertSame($clave, $this->clave($h), $h);
        }
    }

    public function test_domingos_abreviados(): void
    {
        foreach (['Dom. 4' => 4, 'DOMINGO 04' => 4, 'Dgo 11' => 11] as $h => $dia) {
            $c = (new HeaderMapper)->clasificar($h);
            $this->assertSame('domingo', $c['tipo'], $h);
            $this->assertSame($dia, $c['dia']);
        }
    }

    public function test_relleno_de_excel_se_ignora(): void
    {
        foreach (['Columna1', 'Column 12', 'Consecutivo'] as $h) {
            $this->assertSame('relleno', $this->clave($h), $h);
        }
    }

    public function test_numero_de_nomina_con_cualquier_nombre(): void
    {
        // v6: "#" es el número de nómina (primera columna del archivo de RH)
        foreach (['#', 'No de Nomina', 'No. de Nómina', 'Numero de nomina', 'NÚMERO DE NÓMINA', 'Núm. nómina', 'N° nómina', 'Nómina', 'No.'] as $h) {
            $this->assertSame('numero_nomina', $this->clave($h), $h);
        }
        $this->assertSame('num_faltas', $this->clave('# de Faltas'));   // no se confunde con faltas
    }

    public function test_abreviatura_de_dos_columnas_no_se_adivina(): void
    {
        $c = (new HeaderMapper)->clasificar('Bono');   // ¿puntualidad? ¿asistencia? ¿3 meses?
        $this->assertSame('desconocido', $c['tipo']);
        $this->assertSame('ambiguo', $this->clave('Nom.'));   // ¿Nombre o Nómina? (v6) se pregunta

        [, $mensajes, $desconocidas] = (new HeaderMapper)->mapear(['A' => 'Nombre', 'B' => 'Fecha de ingreso', 'C' => 'Bono asis']);
        $this->assertSame([], $desconocidas);   // "asis" solo puede ser asistencia
    }

    public function test_desconocida_trae_sugerencias(): void
    {
        [, , $desconocidas] = (new HeaderMapper)->mapear(['A' => 'Nombre', 'B' => 'Fecha de ingreso', 'C' => 'Faltas del periodo']);
        $this->assertSame('C', $desconocidas[0]['columna']);
        $this->assertSame('Faltas del periodo', $desconocidas[0]['encabezado']);
        $this->assertSame('num_faltas', $desconocidas[0]['sugerencias'][0]['clave']);
    }

    public function test_alias_aprendido_se_reconoce(): void
    {
        $catalogo = CatalogoColumnas::SISTEMA;
        foreach ($catalogo as &$def) {
            if ($def['clave'] === 'num_faltas') {
                $def['alias'][] = 'Faltas del periodo';   // RH lo asignó en el asistente
            }
        }
        $this->assertSame('num_faltas', (new HeaderMapper($catalogo))->clasificar('FALTAS DEL PERIODO')['clave']);
    }

    public function test_orden_distinto_al_de_rh(): void
    {
        $enc = ['A' => 'Nombre', 'B' => 'Puesto', 'C' => 'Fecha de ingreso', 'D' => 'Prima vacacional', 'E' => 'Bono puntualidad',
            'F' => 'Bono 3 meses', 'G' => 'Bono asistencia', 'H' => 'Detalles', 'I' => 'Acredita para bono puntualidad',
            'J' => 'Numero de faltas', 'K' => 'Programado en Domingo', 'L' => 'Domingo 4', 'M' => 'Domingo 11',
            'N' => '1 de octubre al 13 de octubre de 2026', 'O' => 'No de Nomina'];
        [$mapa, $mensajes] = (new HeaderMapper)->mapear($enc);

        $this->assertSame([], $mensajes);
        $this->assertSame('fecha_ingreso', $mapa->campos['C']);
        $this->assertSame('num_faltas', $mapa->campos['J']);
    }

    public function test_clave_de_comparacion(): void
    {
        $this->assertSame('numero faltas', Texto::claveEncabezado('# de Faltas'));
        $this->assertSame('fecha ingreso', Texto::claveEncabezado('F. DE INGRESO'));
        $this->assertSame('acredita bono', Texto::claveEncabezado('Acredita para bono'));
    }

    public function test_bloque_ejercicio_y_periodo(): void
    {
        $m = (new MetadatosHoja)->extraer([
            [null, 'Tipo Periodo:', 'Quincenal', null, 'Incidencias para pago de nomina'],
            [null, 'Ejercicio:', 2026],
            [null, 'Periodo:', '17'],
        ]);
        $this->assertSame(['ejercicio' => 2026, 'numero' => 17, 'tipo' => 'Quincenal', 'titulo' => 'Incidencias para pago de nomina'], $m);
    }

    public function test_bloque_ausente_o_con_valores_raros(): void
    {
        $this->assertSame(['ejercicio' => null, 'numero' => null, 'tipo' => null, 'titulo' => 'REPORTE DE INCIDENCIAS'], (new MetadatosHoja)->extraer([['REPORTE DE INCIDENCIAS']]));
        $m = (new MetadatosHoja)->extraer([['Ejercicio', 'dos mil'], ['Periodo:', null, 99]]);
        $this->assertNull($m['ejercicio']);
        $this->assertNull($m['numero']);   // fuera de 1-60
    }
}
