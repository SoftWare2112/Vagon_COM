/** Íconos de línea (24×24, trazo 1.75) para el menú. Sin dependencias: rutas SVG propias. */
const RUTAS = {
  inicio: 'M3 10.5 12 3l9 7.5M5.5 9v11h13V9M10 20v-6h4v6',
  empleados: 'M16 19v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 17.5V19M10 10.5a3.25 3.25 0 1 0 0-6.5 3.25 3.25 0 0 0 0 6.5M20 19v-1.5a3.5 3.5 0 0 0-2.5-3.35M15.5 4.2a3.25 3.25 0 0 1 0 6.1',
  nomina: 'M5 3h14v18H5zM8.5 7h7M8.5 11h7M8.5 15h3.5M15 15h.5M15 18h.5',
  historial: 'M4 7V5a1 1 0 0 1 1-1h4l2 2h8a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1zM4 10h16',
  reportes: 'M14 3H6v18h12V7zM14 3v4h4M9 17v-3M12 17v-6M15 17v-2',
  anual: 'M7 3h10v18l-2.5-1.5L12 21l-2.5-1.5L7 21zM10 8h4M10 12h4',
  xml: 'M14 3H6v18h12V7zM14 3v4h4M10 12l-2 2 2 2M14 12l2 2-2 2',
  indicadores: 'M4 20h16M7 16v-5M12 16V7M17 16v-8',
  configuracion: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6M19.4 13.5l1.6 1.2-2 3.4-1.9-.7a7 7 0 0 1-1.7 1L15 20.5h-4l-.4-2.1a7 7 0 0 1-1.7-1l-1.9.7-2-3.4 1.6-1.2a7 7 0 0 1 0-2L3 10.3l2-3.4 1.9.7a7 7 0 0 1 1.7-1L9 3.5h4l.4 2.1a7 7 0 0 1 1.7 1l1.9-.7 2 3.4-1.6 1.2a7 7 0 0 1 0 2',
  publicaciones: 'M4 10v4h3l6 4V6L7 10zM16.5 9a4 4 0 0 1 0 6M19 6.5a7.5 7.5 0 0 1 0 11',
  usuarios: 'M6 11V8a6 6 0 0 1 12 0v3M5 11h14v10H5zM12 15v2',
  menu: 'M4 6h16M4 12h16M4 18h16',
} as const;

export type NombreIcono = keyof typeof RUTAS;

export function Icono({ nombre, className = '' }: { nombre: NombreIcono; className?: string }) {
  return (
    <svg className={`svg-icono ${className}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d={RUTAS[nombre]} />
    </svg>
  );
}
