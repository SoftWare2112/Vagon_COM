<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
  /* Misma estructura que el archivo de RH: bloque Tipo Periodo / Ejercicio / Periodo + título + tabla */
  @page { margin: 18px 18px 30px 18px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1f2933; }
  .cabecera { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  .cabecera td { padding: 2px 4px; vertical-align: middle; }
  .etq { font-weight: bold; text-align: right; width: 70px; }
  .val { background: #FFFF99; border: 1px solid #555; text-align: center; width: 70px; }
  .titulo { background: #1BA1E2; color: #fff; font-weight: bold; font-size: 12px; text-align: center; }
  table.datos { width: 100%; border-collapse: collapse; }
  thead { display: table-header-group; }          /* repite encabezados en cada página */
  th { background: #A9DFBF; font-weight: normal; border: 1px solid #555; padding: 3px; text-align: center; }
  td.d { border: 1px solid #999; padding: 2px 3px; }
  tr { page-break-inside: avoid; }
  .f1 { background: #FFF4C2; }  .f2 { background: #FDE0E0; }
  .c { text-align: center; }
  footer { position: fixed; bottom: -18px; left: 0; right: 0; font-size: 7px; color: #6b7280; }
</style>
</head>
<body>
  <table class="cabecera">
    <tr>
      <td class="etq">Tipo Periodo:</td><td class="val">{{ $encabezado['tipo'] }}</td><td style="width:12px"></td>
      <td class="titulo" rowspan="3">{{ $encabezado['titulo'] }}</td>
    </tr>
    <tr><td class="etq">Ejercicio:</td><td class="val">{{ $encabezado['ejercicio'] }}</td><td></td></tr>
    <tr><td class="etq">Periodo:</td><td class="val">{{ $encabezado['numero'] }}</td><td></td></tr>
  </table>

  <table class="datos">
    <thead><tr>@foreach($columnas as $c)<th>{{ $c['etiqueta'] }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach($filas as $f)
      <tr>
        @foreach($f as $i => $v)
          @php
            $tipo = $columnas[$i]['tipo'];
            $esFaltas = $col_faltas !== null && $i === $col_faltas;
            $faltas = $esFaltas ? (int) $v : 0;
            $clase = $esFaltas ? ($faltas === 1 ? 'f1 c' : ($faltas >= 2 ? 'f2 c' : 'c')) : (in_array($tipo, ['texto', 'texto_largo'], true) ? '' : 'c');
          @endphp
          <td class="d {{ $clase }}">{{ $v }}</td>
        @endforeach
      </tr>
    @endforeach
    </tbody>
  </table>
  <footer>{{ $encabezado['periodo'] }} · {{ count($filas) }} registros · generado el {{ $generado }} por {{ $usuario }} · Documento confidencial — uso interno de RH.</footer>
</body>
</html>
