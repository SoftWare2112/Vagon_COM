/**
 * La tabla usa claves "expandidas" (domingo:2026-08-16, extra:bono_x, valor_periodo);
 * el catálogo de columnas usa la clave base. Esta función traduce de una a otra.
 */
export function claveCatalogo(claveTabla: string): string {
  if (claveTabla.startsWith('extra:')) return claveTabla.slice(6);
  if (claveTabla.startsWith('domingo:')) return 'domingos';
  if (claveTabla === 'valor_periodo') return 'periodo';
  return claveTabla;
}

export const COLUMNAS_OBLIGATORIAS = ['nombre_completo', 'fecha_ingreso'];
