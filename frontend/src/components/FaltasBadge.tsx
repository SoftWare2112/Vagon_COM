import { textoFaltas } from '../utils/formato';

/**
 * Semáforo de faltas: 0 neutro, 1 amarillo, 2+ rojo.
 * Además del color lleva ícono y texto (daltonismo / impresión en B/N).
 */
export function FaltasBadge({ faltas }: { faltas: number }) {
  const nivel = faltas === 0 ? 'ninguna' : faltas === 1 ? 'una' : 'varias';
  const icono = faltas === 0 ? '' : faltas === 1 ? '▲ ' : '● ';
  return (
    <span className={`faltas faltas-${nivel}`} title={textoFaltas(faltas)} aria-label={textoFaltas(faltas)}>
      {icono}{faltas}
    </span>
  );
}
