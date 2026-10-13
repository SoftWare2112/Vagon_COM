<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 18px 18px 30px 18px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1f2933; }
  h1 { font-size: 12px; margin: 0 0 2px; }
  .meta { color: #6b7280; margin-bottom: 8px; }
  table { width: 100%; border-collapse: collapse; }
  thead { display: table-header-group; }          /* repite encabezado en cada página */
  th { background: #A9DFBF; font-weight: normal; border: 1px solid #555; padding: 3px; text-align: center; }
  td { border: 1px solid #999; padding: 2px 3px; }
  tr { page-break-inside: avoid; }
  .f1 { background: #FFF4C2; }  .f2 { background: #FDE0E0; }
  .c { text-align: center; }
  footer { position: fixed; bottom: -18px; left: 0; right: 0; font-size: 7px; color: #6b7280; }
</style>
</head>
<body>
  <h1>Reporte de personal — {{ $periodo->etiqueta }}</h1>
  <div class="meta">Generado el {{ $generado }} por {{ $usuario }} · {{ count($filas) }} registros</div>
  <table>
    <thead><tr>@foreach($encabezados as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach($filas as $f)
      <tr>
        @foreach($f as $i => $v)
          @php $esFaltas = $colFaltas !== null && $i === $colFaltas; $faltas = $esFaltas ? (int) $v : 0; @endphp
          <td class="{{ $esFaltas ? ($faltas === 1 ? 'f1 c' : ($faltas >= 2 ? 'f2 c' : 'c')) : '' }}">{{ $v }}</td>
        @endforeach
      </tr>
    @endforeach
    </tbody>
  </table>
  <footer>Documento confidencial — uso interno de RH.</footer>
</body>
</html>
