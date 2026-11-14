import { fechaDeSerial, fechaHoraDeSerial, horaDeFraccion } from './fechas';
import type { Estilo, Valor } from './tipos';

const dma = (iso: string) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`;
const miles = (n: number, dec: number) => n.toLocaleString('es-MX', { minimumFractionDigits: dec, maximumFractionDigits: dec });

/** Texto que se ve en la celda según su valor y su formato de número. */
export function mostrar(v: Valor, fmt: Estilo['fmt'] = 'general'): string {
  switch (v.t) {
    case 'vacio': return '';
    case 'texto': return v.v;
    case 'logico': return v.v ? 'VERDADERO' : 'FALSO';
    case 'error': return v.v;
    case 'fecha': return fmt === 'numero' ? miles(serialSeguro(v.v), 0) : dma(v.v);
    case 'fecha_hora': return `${dma(v.v)} ${v.v.slice(11, 16)}`;
    case 'hora': return v.v.slice(0, 5);
    case 'numero': {
      const n = v.v;
      switch (fmt) {
        case 'numero': return miles(n, 2);
        case 'entero': return miles(Math.round(n), 0);
        case 'moneda': return (n < 0 ? '-$' : '$') + miles(Math.abs(n), 2);
        case 'porcentaje': return `${miles(n * 100, 2)}%`;
        case 'fecha': return dma(fechaDeSerial(n));
        case 'fecha_hora': { const s = fechaHoraDeSerial(n); return `${dma(s)} ${s.slice(11, 16)}`; }
        case 'hora': return horaDeFraccion(n);
        default: return String(+n.toPrecision(12));
      }
    }
  }
}

function serialSeguro(iso: string): number {
  return Math.round((Date.parse(`${iso}T00:00:00Z`) - Date.UTC(1899, 11, 30)) / 86400000);
}

/** Alineación por defecto: números a la derecha, texto a la izquierda, lógicos y errores al centro. */
export function alineacion(v: Valor, estilo?: Estilo): 'izq' | 'centro' | 'der' {
  if (estilo?.a) return estilo.a;
  if (v.t === 'numero' || v.t === 'fecha' || v.t === 'fecha_hora' || v.t === 'hora') return 'der';
  if (v.t === 'logico' || v.t === 'error') return 'centro';
  return 'izq';
}

/** Lo que aparece al editar una celda que NO es fórmula (sin separador de miles). */
export function textoParaEditar(v: Valor): string {
  switch (v.t) {
    case 'vacio': return '';
    case 'numero': return String(+v.v.toPrecision(15));
    case 'fecha': return dma(v.v);
    case 'fecha_hora': return `${dma(v.v)} ${v.v.slice(11, 16)}`;
    case 'texto': return /^[=+\-\d$.,%/:]/.test(v.v) || /^(verdadero|falso|true|false)$/i.test(v.v) ? `'${v.v}` : v.v;
    default: return mostrar(v);
  }
}
