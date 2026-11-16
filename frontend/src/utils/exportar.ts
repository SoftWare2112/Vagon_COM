/**
 * v12 — Exportar una tabla a Excel como CSV (UTF-8 con BOM para que Excel respete los acentos).
 * Para PDF se usa la impresión del navegador («Guardar como PDF»), con estilos @media print.
 */
export function descargarCSV(nombre: string, filas: (string | number | null | undefined)[][]) {
  const celda = (v: string | number | null | undefined) => {
    if (v == null) return '';
    const s = typeof v === 'number' ? String(Math.round(v * 100) / 100) : v;
    return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const texto = '﻿' + filas.map((f) => f.map(celda).join(',')).join('\r\n');
  const url = URL.createObjectURL(new Blob([texto], { type: 'text/csv;charset=utf-8' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = nombre.endsWith('.csv') ? nombre : `${nombre}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}
