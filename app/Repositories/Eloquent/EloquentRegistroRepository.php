<?php

namespace App\Repositories\Eloquent;

use App\Models\Columna;
use App\Models\Registro;
use App\Repositories\Contracts\RegistroRepository;
use App\Repositories\FiltrosTabla;
use App\Support\Texto;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EloquentRegistroRepository implements RegistroRepository
{
    private const COLUMNAS_ORDEN = [
        'numero' => 'e.numero_empleado',
        'numero_nomina' => 'registros.numero_nomina',
        'nombre_completo' => 'e.nombre_normalizado',
        'puesto' => 'p.nombre',
        'fecha_ingreso' => 'e.fecha_ingreso',
        'num_faltas' => 'registros.num_faltas',
        'prima_vacacional' => 'registros.prima_vacacional',
        'bono_3_meses' => 'registros.bono_3_meses',
        'bono_puntualidad' => 'registros.bono_puntualidad',
        'bono_asistencia' => 'registros.bono_asistencia',
        'acredita_bono' => 'registros.acredita_bono',
        'programado_domingo' => 'registros.programado_domingo',
        'valor_periodo' => 'registros.valor_periodo',
    ];

    public function tabla(FiltrosTabla $f): LengthAwarePaginator
    {
        return $this->consulta($f)->paginate($f->porPagina)->withQueryString();
    }

    public function paraExportar(FiltrosTabla $f): Collection
    {
        return $this->consulta($f)->get();
    }

    public function faltasDelMes(Registro $registro): int
    {
        $mes = $registro->periodo->fecha_fin;

        return (int) Registro::query()
            ->where('empleado_id', $registro->empleado_id)
            ->whereHas('periodo', fn ($q) => $q->whereYear('fecha_fin', $mes->year)->whereMonth('fecha_fin', $mes->month))
            ->sum('num_faltas');
    }

    private function consulta(FiltrosTabla $f): Builder
    {
        $q = Registro::query()
            ->select('registros.*')
            ->join('empleados as e', 'e.id', '=', 'registros.empleado_id')
            ->leftJoin('puestos as p', 'p.id', '=', 'registros.puesto_id')   // puesto de ESA quincena
            ->where('registros.periodo_id', $f->periodoId)
            ->with(['empleado', 'puesto', 'domingos', 'periodo']);

        if ($f->nombre) {
            $q->where('e.nombre_normalizado', 'like', '%'.$this->escaparLike(Texto::normalizar($f->nombre)).'%');
        }
        if ($f->puesto) {
            ctype_digit($f->puesto)
                ? $q->where('registros.puesto_id', (int) $f->puesto)
                : $q->where('p.nombre', 'like', '%'.$this->escaparLike($f->puesto).'%');
        }
        if ($f->ingresoDesde) {
            $q->whereDate('e.fecha_ingreso', '>=', $f->ingresoDesde);
        }
        if ($f->ingresoHasta) {
            $q->whereDate('e.fecha_ingreso', '<=', $f->ingresoHasta);
        }
        if ($f->faltas !== null) {
            $q->where('registros.num_faltas', $f->faltas);
        }

        if ($f->orden === 'numero_nomina') {
            // Orden del archivo de RH (200, 201, ...); filas sin número al final y por nombre
            $q->orderByRaw('registros.numero_nomina IS NULL')->orderBy('registros.numero_nomina', $f->direccion)->orderBy('e.nombre_normalizado');
        } elseif (isset(self::COLUMNAS_ORDEN[$f->orden])) {
            $q->orderBy(self::COLUMNAS_ORDEN[$f->orden], $f->direccion);
        } elseif (str_starts_with($f->orden, 'extra:') && Columna::where('clave', $clave = substr($f->orden, 6))->where('origen', 'personalizada')->exists()) {
            // Columnas agregadas por RH: se ordena por el valor dentro del JSON (clave validada contra el catálogo)
            $q->orderByRaw('JSON_UNQUOTE(JSON_EXTRACT(registros.valores_extra, ?)) '.($f->direccion === 'desc' ? 'desc' : 'asc'), ['$."'.$clave.'"']);
        } else {
            $q->orderBy('e.nombre_normalizado');
        }

        return $q->orderBy('registros.id');
    }

    private function escaparLike(string $v): string
    {
        return addcslashes($v, '%_\\');
    }
}
